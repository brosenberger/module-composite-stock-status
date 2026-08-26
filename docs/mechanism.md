---
okf_version: "0.2"
type: Explanation
title: How the composite stock latch works
description: The three-line expression that latches a composite parent out of stock, where the zero comes from, and why the fix hooks product creation.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Mechanism

## The latch

`ChangeParentStockStatus::isNeedToUpdateParent()` — the same three lines in
`Magento\ConfigurableProduct`, `Magento\Bundle` and `Magento\GroupedProduct`:

```php
return $parentStockItem->getIsInStock() !== $childrenIsInStock &&
    ($childrenIsInStock === false || $parentStockItem->getStockStatusChangedAuto());
```

Read it in two directions:

* **Children go out of stock** → `$childrenIsInStock === false` is true → the
  parent is always allowed to follow them out.
* **Children come back** → the second clause falls through to
  `getStockStatusChangedAuto()`. If that is `0`, the parent never returns.

The flag records "this status was set automatically rather than by a human", so
core is refusing to overrule what it believes was a deliberate decision.

## Where the zero comes from

Nobody made a decision. A product created through the API without stock data is
written with `is_in_stock = 0` and `stock_status_changed_auto = 0` — the column
defaults. The parent is therefore **born latched**, and the natural import order
walks straight into it:

1. create the parent and children (no stock data yet)
2. link the children
3. send stock in a second feed

Step 3 revives nothing. This is not indexer lag; the flag is the source of truth
and the index reproduces it faithfully.

## Why the fix hooks product creation

The obvious hook is a plugin on `StockItemRepositoryInterface::save()` that
recognises the row being created. It does not survive across versions: on
**2.4.8-p5** the stock item has no `item_id` at that point, on **2.4.9** the row
already exists and carries one. The first implementation of this module used that
test and silently did nothing on 2.4.9.

Product creation is the same event on both. The module remembers in `beforeSave`
whether the product had an id, and in `afterSave` marks a newly created composite
as automatically maintained.

## Why the flag is written directly

`SetAutomaticStockStatusFlag` issues a single `UPDATE` rather than saving a stock
item, for two reasons:

* It runs inside a product save; going back through the stock item repository
  from there re-enters the save it was called from.
* A repository round-trip is served from the request-level stock registry cache.
  Setting a field to what the cached object already holds is not a data change,
  the save becomes a no-op, and the write silently does not happen — which is
  exactly how the repair command failed its first test.

`stock_status_changed_auto` drives no index and no cache. It only tells core
whether it may move the status on its own.

## The multi-source pair

On an install with two or more **enabled** sources, two separate defects compound.

**One: the recompute is gated off.** All three parent-recompute call sites test
`IsSingleSourceMode`, which is `count(enabled sources) < 2`. A second enabled
source — assigned to no stock, no website and no product — freezes every
composite parent's stored flag at whatever it last held.

**Two: secondary stocks are vetoed by the default stock.**
`InventoryConfigurableProductIndexer\Indexer\SelectBuilder` builds

```sql
IF(inventory_stock_item.is_in_stock = 0, 0, MAX(stock.is_salable))
```

with `inventory_stock_item` joined on `stock_id = <default stock id>`. That
builder only ever runs for **non-default** stocks — `Stock\Strategy\Sync` and
`SkuListsProcessor` both `continue` on the default stock — so the join imports a
value scoped to a different stock and lets it veto the result.

Together: an API-created configurable starts at `is_in_stock = 0`, the gate means
it can never be corrected, and the veto turns that zero into "unsalable in every
stock". Measured on pure core 2.4.9, every one of five scenarios gave
`salable = 0` in both stocks — including "children in stock at both sources".

Defect two is also why defect one's gate exists. Removing the gate alone makes
things *worse*: the recompute then writes a correct default-scoped flag, and the
veto propagates it into secondary stocks, marking parents unsalable in stocks
whose children are fine. That is the corruption reported as
magento/inventory#3350, and this module reproduced it before fixing the veto.

So the two fixes are a pair. The veto must go first; only then is running the
recompute in multi-source safe.

