---
okf_version: "0.2"
type: Knowledge Bundle
title: BroCode_CompositeStockStatus — Knowledge Bundle
description: A Magento 2 module that stops configurable, bundle and grouped products from being permanently stuck out of stock after an API import.
resource: https://github.com/brosenberger/module-composite-stock-status
tags: [magento2, inventory, configurable, bundle, grouped, stock-status, integration]
generated:
  by: claude-opus-5
  at: 2026-08-26T00:00:00Z
status: stable
stale_after: 2027-08-26T00:00:00Z
---

# BroCode_CompositeStockStatus — Knowledge Bundle

A composite parent's stock status is a **stored flag**, and core will only move it
back *into* stock when the parent is marked as automatically maintained. A product
created through the API without stock data is born out of stock **and** not
automatically maintained — so the import order every ERP feed uses produces
parents that can never recover, no matter how much quantity their children get.

This module makes a newly created composite start in the automatic state, and
ships a command to repair catalogues that are already stuck.

# Using it

* [Usage](usage.md) — installing it, and repairing an existing catalogue
* [Limits](limits.md) — multi-source mode, and what this deliberately does not fix

# Understanding it

* [Mechanism](mechanism.md) — the latch, where it comes from, and why the hook moved
* [Verification](verification.md) — what was executed on 2.4.8-p5 and 2.4.9, and two measurement errors that produced false readings

# History

* [Log](log.md)
