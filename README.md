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

**It does not make a manual out-of-stock on a composite permanent.** A merchant's
decision survives child stock movement, but the next save of that parent puts it
back under automatic control. Core does the same — it clears the marker on every
product save — so the decision was never durable across an edit either way. To
take a composite off sale for good, disable it or take its children out of stock.

**It does not rewrite an index expression it does not recognise.** If a future
Magento version changes one of the three select builders, the rewrite becomes a
no-op and the defect returns *visibly*, rather than the plugin emitting broken
SQL. Check [Verification](docs/verification.md) against your version before
relying on it.

**It does not touch simple products.** Their `stock_status_changed_auto` is a
genuine merchant setting — it is what stops a manual out-of-stock being undone by
the next quantity write — and is left alone.

Full detail in [Limits](docs/limits.md).

## Reported upstream

Both defects have been reported to Magento repeatedly, from different angles and
by different people. None of the reports has produced a fix, and two were closed
without one — which is the reason this module exists.

**The recompute being gated off** (the parent's stored status freezing):

| Issue | Reported from | State |
|---|---|---|
| [magento2#36154](https://github.com/magento/magento2/issues/36154) | admin, custom stock: saving a child does not update the parent | **open**, Confirmed, P2, *dev in progress* |
| [magento2#37960](https://github.com/magento/magento2/issues/37960) | REST, grouped: parent does not follow its children back in stock | **open**, Confirmed, P2, *ready for dev* |
| [magento2#32192](https://github.com/magento/magento2/issues/32192) | CSV import, configurable — names `ParentItemProcessor::processStockForParent` as the bypassed mechanism | closed 2025-02 as completed, with no linked fix and still labelled *ready for dev* |

**The default-stock veto** (secondary stocks answering with another stock's flag):

| Issue | Reported from | State |
|---|---|---|
| [inventory#3350](https://github.com/magento/inventory/issues/3350) | the other direction — a parent marked unsalable for non-default stocks. Diagnoses the cause as the recompute "not taking the inventory setup into account at all" | closed as completed, still labelled *ready for grooming* |
| [inventory#3454](https://github.com/magento/inventory/issues/3454) | parents permanently stuck out of stock through a self-referential read in the legacy indexer expression | **open**, awaiting confirmation |

inventory#3350 is worth reading before changing any of this. It is what makes the
gate look necessary — and it is why the two fixes here have to ship together.
Lifting the gate on its own reproduces it exactly: the recompute writes a correct
default-scoped flag, and the veto propagates it into secondary stocks. That was
measured here before the veto was fixed.

**Adjacent, useful for context:**

| Issue | Why it is relevant | State |
|---|---|---|
| [magento2#18999](https://github.com/magento/magento2/issues/18999) | its payload contains `"stock_status_changed_auto": 0` — the field is public and writable, and a template copied from it latches every parent it touches | closed 2019 |
| [magento2#40101](https://github.com/magento/magento2/issues/40101) | add-to-cart failing with `is_salable = 0` in `inventory_stock_3` after REST source-item writes: the veto's symptom, seen from the storefront | closed as *needs update* |
| [magento2#36421](https://github.com/magento/magento2/issues/36421) | a genuinely fixed MSI reindex gap on the API path — different layer, often mistaken for this one | closed, P1, fixed |
| [magento2#30088](https://github.com/magento/magento2/issues/30088), [inventory#3358](https://github.com/magento/inventory/issues/3358) | older reports of the same family | closed |

A consolidated report covering both defects together, with the reproduction and
the per-type fix, is drafted but not yet filed.

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
