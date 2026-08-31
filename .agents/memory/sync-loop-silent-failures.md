---
name: Sync loops must persist failures, not just log them
description: Why a per-order sync loop that only logs exceptions makes failing orders invisible in the dashboard, and the fix pattern used for it.
---

In this JTL<->Packiyo sync app, per-order sync loops (e.g. fulfillment tracking sync) historically only wrote to the log file/`sync_logs` table when a single order's step failed (e.g. no matching JTL delivery note yet). Nothing was written to the persisted result table (`fulfillment_syncs`) for that order, so:

- The order silently disappears from the dashboard's history/list view instead of showing as an error.
- It keeps retrying (and failing) on every run with no visible sign to the user beyond a transient one-line summary banner right after a manual click, or digging through the Logs tab.
- Filtering the list by customer then looks like "only N orders exist" when really the rest are failing quietly every run.

**Why:** The dashboard list pages here are backed by persisted attempt tables, not live/pending queues — a row only exists once some sync path calls `upsert()`. A caught-and-logged-only exception path breaks that invariant.

**How to apply:** Any new or modified per-item sync loop should persist a `failed` (or similarly distinct) status row with the error message when an item fails a step, using the same upsert key as the success path, and make sure the "is this done" check (e.g. `exists()`/`pendingFulfillment()`) does NOT treat that failed status as done — so it's both visible and still retried. Give failed rows their own visual status class in the dashboard (not lumped in with unrelated warning states) so users can tell "still trying" apart from "actually broken, needs attention."
