---
okf_version: "0.2"
type: Reference
title: Limits of BroCode_CompositeStockStatus
description: Multi-source mode, the manual-override boundary, and what ages.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Limits

## Multi-source mode is not addressed

Every parent-stock recompute path in core is gated on `IsSingleSourceMode`:

```php
// Magento\InventoryCatalog\Model\IsSingleSourceMode
return $searchResult->getTotalCount() < 2;   // enabled sources
```

Creating a **second enabled source — even one assigned to no stock, no website
and no product** — flips that to false and stops composite parent maintenance
entirely. Measured: with two enabled sources, taking every child out of stock
left the parent `is_in_stock = 1`.

What that does *not* do is break the storefront. Salability is still computed
correctly in multi-source, so the product is hidden as it should be; only the
stored flag goes stale — the value the admin grid, the product form and
`GET /V1/stockItems/{sku}` report. The damage is to anything that reads the
status back and believes it, not to what customers can buy.

This module does not change that, deliberately. The naive fix runs the legacy
recompute anyway, which derives the parent flag from default-source data only and
marks parents unsalable for non-default stocks — reported as
magento/inventory#3350. A correct fix needs a per-stock answer rather than one
flag, and belongs upstream (magento/magento2#36154 is open, P2).

If you run multi-source, treat parent stock flags as unmaintained and reconcile
them yourself.

## The manual-override boundary

Only **creation** is touched. A merchant who later sets a configurable out of
stock in the admin writes `stock_status_changed_auto = 0`, and this module leaves
that alone — the parent stays out of stock until someone puts it back.

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
