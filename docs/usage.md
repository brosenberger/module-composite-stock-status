---
okf_version: "0.2"
type: How-To
title: Using BroCode_CompositeStockStatus
description: Installing the module and repairing composite products that are already stuck out of stock.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Usage

## Install

```bash
ddev composer require brocode/module-composite-stock-status
ddev exec php bin/magento module:enable BroCode_CompositeStockStatus
ddev exec php bin/magento setup:upgrade
```

Nothing to configure. From then on, every composite product created through any
path — REST, GraphQL, the admin, a data patch — starts with
`stock_status_changed_auto = 1`, which is what lets core follow its children.

## Repair an existing catalogue

The plugin only affects products created after installation. Everything imported
before it is still stuck:

```bash
ddev exec php bin/magento brocode:composite-stock:revive --dry-run   # report only
ddev exec php bin/magento brocode:composite-stock:revive             # whole catalogue
ddev exec php bin/magento brocode:composite-stock:revive SKU-1 SKU-2 # named parents
```

The command finds parents in exactly the state core refuses to act on —
`is_in_stock = 0` **and** `stock_status_changed_auto = 0` — clears the flag, and
then asks Magento's own per-type processors to re-derive the status. A parent
whose children genuinely are all out of stock stays out of stock.

## Checking whether you have the problem

```sql
SELECT p.sku, p.type_id
FROM   catalog_product_entity p
JOIN   cataloginventory_stock_item si ON si.product_id = p.entity_id
WHERE  p.type_id IN ('configurable', 'bundle', 'grouped')
  AND  si.is_in_stock = 0
  AND  si.stock_status_changed_auto = 0;
```

Every row is a parent that will not come back on its own. This is a diagnostic,
not something to run from an integration.
