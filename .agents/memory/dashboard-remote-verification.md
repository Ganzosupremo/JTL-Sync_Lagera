---
name: Dashboard remote verification
description: Keeps the JTL order list responsive when external APIs or the Cloudflare tunnel are slow.
---

The JTL orders list must use local order mappings by default and perform Packiyo order verification only when a user explicitly requests it for an individual order.

**Why:** Per-row external verification turns a single page load into many serial API requests. When JTL, Packiyo, or the Cloudflare tunnel is slow, those requests can saturate PHP workers and surface as Cloudflare origin timeouts.

**How to apply:** Preserve local mapping status for the normal list view. Any new remote freshness check on an order collection needs an explicit, bounded action rather than running automatically for every displayed row.