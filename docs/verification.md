---
okf_version: "0.2"
type: Evidence
title: Verification of BroCode_CompositeStockStatus
description: What was executed against running 2.4.8-p5 and 2.4.9 installs, and the two measurement errors that produced false readings.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# Verification

Everything below was executed against running installs, not reasoned from source.

## The A/B, on both versions

One script, one module toggle, on a purged catalogue:

| | at birth | children stocked | children all out | children restocked |
|---|---|---|---|---|
| 2.4.8-p5, module off | 0 / 0 | **0 / 0** | 0 / 0 | **0 / 0** |
| 2.4.8-p5, module on  | 0 / 1 | **1 / 1** | 0 / 1 | **1 / 1** |
| 2.4.9, module off    | 0 / 0 | **0 / 0** | 0 / 0 | **0 / 0** |
| 2.4.9, module on     | 0 / 1 | **1 / 1** | 0 / 1 | **1 / 1** |

`is_in_stock / stock_status_changed_auto`. The defect is identical on both
versions; so is the fix. The parent tracks its children in **both** directions
and repeatedly, so the module does not simply force products in stock.

## Test suite

* 12 unit tests.
* 5 integration tests. With the module added to the integration installer's
  `disable-modules`, the two fix-specific tests fail and the invariants pass —
  so the suite genuinely discriminates rather than passing either way.

## Two measurement errors worth recording

Both produced confident, wrong readings before being caught.

**`deleteById()` silently fails without `isSecureArea`.** The probe's teardown
threw, was swallowed, and left the products behind. Every later "create" was
really an *update* of a product that already carried the previous run's flags —
which briefly made 2.4.9 look like it did not have the defect at all. The fix is
to register `isSecureArea` and to assert the purge worked before continuing.

**The same trap inside the test suite.** With DB isolation disabled, a fixture
surviving teardown is inherited by the next test *along with the stock the
previous test gave it*, which made a reviver test report a parent revived from
children that should have had no stock.

The general lesson: when a cleanup step can fail silently, assert that it
succeeded, or every subsequent measurement is suspect.
