---
name: JTL delivery note shipped status vs shipping-method field
description: Why JTL/BOL fulfillment status does not depend on a carrier field on delivery note packages, and what actually flips it.
---

JTL's delivery-note package API (all versions checked, v1.0-v2.1, both legacy `/deliveryNotes/...` and versioned `/v1/deliveryNotes/...` / `/v2/delivery-notes/...` paths) only accepts `TrackingID`/`trackingID`, `ShippedDate`/`shippedDate`, and `Comment`/`comment` when creating or patching a package. The `shippingMethodId` field that appears when *reading* a package is read-only — JTL derives it itself; there is no way to set a carrier/shipping-method value on a package via this API.

What actually marks an order "shipped" (and is what marketplaces like BOL depend on via JTL-Worker's sync) is JTL's built-in delivery-note workflow event, triggered via `POST .../deliveryNotes/{id}/workflow/{workflowEventId}` with a fixed enum: `1=Created, 2=Deleted, 3=Shipped`. This is separate from, and required in addition to, creating the tracking package. It needs the `deliverynote.triggerdeliverynoteworkflow` OAuth scope (distinct from `deliverynotes.read`/`deliverynotes.write`).

**Why:** Confirmed against JTL's full OpenAPI schemas (v1.0-v2.1), not just the rendered docs pages, which can omit or truncate schema fields.

**How to apply:** If BOL/marketplace fulfillment status looks wrong after tracking is sent to JTL, check whether the delivery note's "Shipped" workflow event was triggered (not just whether a package/tracking exists). Don't try to smuggle carrier info into package fields — JTL's API doesn't support it at any version.
