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
should start in the automatic state. On creation only, the module sets
`stock_status_changed_auto = 1`, and core does the rest:

| step | parent `is_in_stock` | parent `auto` |
|---|---|---|
| catalogue created, no stock sent yet | 0 | 1 |
| every child given quantity and set in stock | **1** | 1 |
| every child taken out of stock | **0** | 1 |
| children restocked again | **1** | 1 |

Because only creation is touched, a merchant who later takes a configurable off
sale by hand keeps that decision.

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

It does not touch **multi-source mode**. Every parent-stock recompute path in
core is gated on `IsSingleSourceMode`, which is true only while fewer than two
*enabled* sources exist — creating a second enabled source, even one assigned to
nothing, stops parent maintenance completely. That is a reporting problem rather
than a storefront one: salability stays correct, and only the stored flag the
admin and the REST stock-item endpoint report goes stale. That is a real gap, but the naive
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
