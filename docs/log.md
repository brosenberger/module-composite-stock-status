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

## 2026-08-26 — multi-source support

* Added the second half of the problem: on two or more enabled sources, core
  gates the parent recompute off *and* vetoes every secondary stock's salability
  with the default stock's flag, so an API-created configurable is unsalable
  everywhere, permanently.
* Two plugins: one rewrites the sibling index select to derive each stock's
  answer from its own data, one runs the recompute in multi-source.
* The select plugin carries `afterExecute` and `afterGetSelect` because the class
  implements a different interface on 2.4.8 than on 2.4.9. A preference cannot
  span both.
* First attempt matched the column expression as a string; it arrives as a
  `Zend_Db_Expr` and the fix silently did nothing.
* Verified on 2.4.8-p5 and 2.4.9. 20 unit tests, 5 integration tests.

