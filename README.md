# BroCode_CompositeStockStatus

Stops Magento 2 configurable, bundle and grouped products from being permanently
stuck out of stock after an API import.

## The problem

Core will only move a composite parent **back into** stock when the parent's
`stock_status_changed_auto` flag is set:

```php
// Magento\ConfigurableProduct\Model\Inventory\ChangeParentStockStatus
private function isNeedToUpdateParent(StockItemInterface $parentStockItem, bool $childrenIsInStock): bool
{
    return $parentStockItem->getIsInStock() !== $childrenIsInStock &&
        ($childrenIsInStock === false || $parentStockItem->getStockStatusChangedAuto());
}
```

Going *out* of stock is unconditional. Coming *back* requires that flag. The flag
means "this status was set automatically rather than by a human".

A product created through the REST API without stock data is born
`is_in_stock = 0` **and** `stock_status_changed_auto = 0`. So the import order
essentially every ERP feed uses — structure first, stock second — produces
parents that can never recover:

| step | parent `is_in_stock` | parent `auto` |
|---|---|---|
| catalogue created, no stock sent yet | 0 | 0 |
| every child given quantity and set in stock | **0** | 0 |
| children restocked again | **0** | 0 |

Reindexing does not help: the parent's flag is stored, not derived, so the index
faithfully reproduces it. The identical three-line expression appears in
`ConfigurableProduct`, `Bundle` and `GroupedProduct`.

Verified on **2.4.8-p5** and **2.4.9**, single-source mode, MSI enabled.

## What this module does

A composite product's stock status is by definition derived from its children,
so a *newly created* composite has had no human decision applied to it and
should start in the automatic state. The module keeps `stock_status_changed_auto = 1` on composite products, and core
does the rest. It re-applies on **every** save, not just creation: core clears the
flag again on any later product save — measured on a grouped product as create
(1), attach links (0), rename (0) — so a creation-only fix stops working on the
second write, which every real import performs.

| step | parent `is_in_stock` | parent `auto` |
|---|---|---|
| catalogue created, no stock sent yet | 0 | 1 |
| every child given quantity and set in stock | **1** | 1 |
| every child taken out of stock | **0** | 1 |
| children restocked again | **1** | 1 |

A merchant who takes a composite off sale by hand keeps that decision through
child stock movements; it is reset by the next save of that parent, after which
the parent follows its children again. Core behaves the same way, so the decision
was never durable across an edit. To take a composite off sale for good, disable
it or take its children out of stock.

## The second problem: multi-source

On an install with **two or more enabled sources**, two further defects compound
into something worse than the first:

1. Every parent-stock recompute path in core is gated on `IsSingleSourceMode`,
   which is `count(enabled sources) < 2`. A second enabled source — even one
   assigned to no stock, no website and no product — stops composite parent
   maintenance completely, so the parent's stored flag freezes at whatever it
   last held.
2. Each composite type's index select folds the parent's legacy stock row into
   the answer — configurable and bundle join it on the **default** stock
   explicitly, grouped joins it with no stock filter at all. Those builders only
   ever run for *non-default* stocks, since the indexer skips the default stock
   outright, so in every case a value scoped to one stock vetoes another stock's
   answer. All three types are affected: configurable, bundle and grouped.

Frozen flag plus veto means: an API-created configurable starts at
`is_in_stock = 0`, can never be updated, and is therefore **unsalable in every
stock, permanently**. Measured on pure core, 2.4.9, five scenarios, all five
`salable = 0` in both stocks including "in stock at both sources".

This module fixes both: salability for a stock is derived from that stock's own
data, and the parent recompute runs in multi-source too (it short-circuits in
single-source mode, where core already does it). Result on the same five
scenarios:

| children | parent flag | salable, default stock | salable, second stock |
|---|---|---|---|
| in stock at both sources | 1 | 1 | 1 |
| out at default, in at source B | 0 | 0 | **1** |
| out at both | 0 | 0 | 0 |
| back in at default only | 1 | 1 | **0** |
| back in at both | 1 | 1 | 1 |

## Repairing a catalogue that is already stuck

The plugin only helps products created after it is installed. For everything
imported before that:

```bash
# see what is stuck, change nothing
ddev exec php bin/magento brocode:composite-stock:revive --dry-run

# repair the whole catalogue
ddev exec php bin/magento brocode:composite-stock:revive

# or just a few parents
ddev exec php bin/magento brocode:composite-stock:revive SKU-1 SKU-2
```

"Stuck" is exactly the pair core refuses to act on: `is_in_stock = 0` together
with `stock_status_changed_auto = 0`. The command clears the flag and then hands
the work to Magento's own per-type processors, so a parent whose children really
are all out of stock stays out of stock.

## What this module deliberately does not do

It does not attempt to reconcile a catalogue that was already broken *before*
installation in ways the repair command cannot see — see [Limits](docs/limits.md). That is a real gap, but the naive
fix writes a parent flag derived from default-source data only and corrupts
salability for non-default stocks (see magento/inventory#3350). It needs a
per-stock answer rather than a single flag, and belongs upstream.

## Installation

```bash
ddev composer require brocode/module-composite-stock-status
ddev exec php bin/magento module:enable BroCode_CompositeStockStatus
ddev exec php bin/magento setup:upgrade
```

## Tests

```bash
# unit
ddev exec vendor/bin/phpunit -c app/code/BroCode/CompositeStockStatus/phpunit.xml.dist

# integration (absolute container path is required)
ddev exec vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
  /var/www/html/app/code/BroCode/CompositeStockStatus/Test/Integration
```

## Licence

MIT. See [LICENSE](LICENSE).
