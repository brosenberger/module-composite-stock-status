---
okf_version: "0.2"
type: Reference
title: Limits of BroCode_CompositeStockStatus
description: What the multi-source fix covers, the manual-override boundary, and what ages.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Limits

## What multi-source support does and does not cover

The module makes composite parents behave correctly in multi-source mode by
deriving each stock's salability from that stock's own data and by running the
parent recompute that core gates off. It does this with two plugins on core
classes:

- `InventoryConfigurableProductIndexer\Indexer\SelectBuilder` — the column
  rewrite. The plugin carries both `afterExecute` (2.4.8 and earlier) and
  `afterGetSelect` (2.4.9+), because the class changed interface between them.
  **If a future version changes the expression again, the plugin deliberately
  does nothing** rather than guess, so the defect returns silently. Check
  `docs/verification.md` against your version before trusting it.
- `SourceItemsSaveInterface` — the recompute. It short-circuits in single-source
  mode, where core already does the work.

All three composite types are covered — configurable, bundle and grouped — each
with its own index select builder and its own expression shape. The rewrite is
driven by two narrow patterns and is a no-op on anything it does not recognise.

One genuine platform constraint sits underneath this: a bundle whose shipment
type is **Ship Together** cannot have multi-source children at all. Magento
refuses the source assignment outright with a validation error. Multi-source
bundles must ship separately, which is core behaviour and not something this
module changes.

## The manual-override boundary

Only **creation** is touched. A merchant who later sets a configurable out of
stock in the admin writes `stock_status_changed_auto = 0`, and this module leaves
that alone — the parent stays out of stock until someone puts it back.

**The flag is not a universal signal, though.** Measured on `2.4-develop`
(2026-08-30), setting a composite parent out of stock through
`StockRegistryInterface::updateStockItemBySku()` leaves the row at
`(is_in_stock = 0, stock_status_changed_auto = 1)` for configurable, bundle and
grouped alike — the flag stays `1` whether core moved the status or a human did.
The `= 0` behaviour above was measured on the admin product-save path on
2.4.8-p5/2.4.9 and holds there, but do not assume the flag identifies a merchant
decision on every path.

## What this module trades away

This is the one place where behaviour deliberately differs from core, and it is
not a bug.

Core propagates a merchant's manual out-of-stock on a composite parent to
**non-default stocks** through the very expression this module strips: each
composite indexer folds the parent's default-stock `cataloginventory_stock_item`
row into every non-default stock's `is_salable`. That is the mechanism, and it is
deliberate upstream — `InventoryCatalog/Test/Integration/CompositeProductReindexOnNonDefaultStockTest`
asserts it for all three composite types, and those tests fail the moment the
expression is qualified.

`Plugin/UseStockScopedSalability` removes that veto so a parent can be salable in
a stock whose sources hold stock. The cost follows directly: with this module
installed on a multi-stock catalogue, **a merchant's manual out-of-stock on a
composite parent very likely stops applying to non-default stocks.** It still
applies to the default stock.

This is deduced from core's mechanism rather than measured end to end on
2.4.8-p5, and is worth confirming before relying on it either way. If merchants
set composite stock by hand on a multi-stock catalogue, treat it as a real
limitation.

The trade-off is also the reason core cannot simply adopt what this module does —
see *Upstream* below.

The repair command will clear that flag, because an operator running it has
explicitly asked for the status to be re-derived. Use `--dry-run` first on a
catalogue where merchants set composite stock by hand.

## What ages

* The latch expression is identical on 2.4.8-p5 and 2.4.9 and is duplicated in
  three modules. If any copy changes, re-check.
* The point at which the stock row is created already moved between 2.4.8 and
  2.4.9. The module no longer depends on it, but anything else that does will
  break.
* `IsSingleSourceMode` counting *enabled sources* rather than sources in use is
  the kind of definition that gets tightened.
* The index expression grew an `AND <manage stock>` term on `2.4-develop`
  (ACP2E-4866, 2026-05-08) that is **not** in 2.4.8-p5 or 2.4.9. When it ships,
  `UseStockScopedSalability::VETO_WRAPPER` stops matching and the plugin silently
  no-ops — `strip()` returns `null` on an unrecognised expression by design, so
  the failure is quiet. Re-check on the first 2.4.10 / 2.5 build.

## Upstream

The behaviour this module works around was investigated against
`magento/magento2@2.4-develop` and `magento/inventory@develop` in August 2026.

* [magento/inventory#3466](https://github.com/magento/inventory/issues/3466) —
  why core cannot fix the multi-source half the way this module does. Removing
  the `IsSingleSourceMode` gate alone reproduces
  [#3350](https://github.com/magento/inventory/issues/3350) (measured A/B);
  removing the gate *and* the veto breaks the intentional propagation described
  above; and `stock_status_changed_auto` cannot tell the two cases apart. A fix
  needs a stock-aware parent recompute or a new signal, which is a design change.
* [magento/inventory#3467](https://github.com/magento/inventory/pull/3467) —
  regression coverage for the gate, contributed upstream. Adds no production
  change.

That investigation **confirms this module's pairing**: lifting the gate without
also removing the veto reproduces #3350, which is why the two plugins ship
together.
