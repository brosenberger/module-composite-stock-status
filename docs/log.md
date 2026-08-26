---
okf_version: "0.2"
type: Log
title: BroCode_CompositeStockStatus — change log
description: Dated record of what changed in this bundle and why.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Log

## 2026-08-26 — initial bundle

* Module created: plugin on product creation plus a repair command.
* Hook moved from `StockItemRepositoryInterface::save()` to
  `ProductRepositoryInterface::save()` after the first implementation was found
  to do nothing on 2.4.9 — the stock row already exists by then on that version.
* Repair command switched from a stock-item save to a direct flag write after the
  request-level stock registry cache turned the save into a no-op.
* Console command dependencies moved behind proxies; without them the CLI builds
  the whole service graph during `setup:install` and the installer dies in
  `Session\Config`.
* Verified on 2.4.8-p5 and 2.4.9. See [verification](verification.md).
