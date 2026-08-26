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

It does **not** touch bundle or grouped parents in multi-source: those types have
their own index select builders, which have not been measured here. Only the
configurable path is verified.

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
