---
name: JTL sales order workflow trigger endpoint
description: The one real REST endpoint shape for triggering a JTL-Wawi sales order workflow event, and why a guessed fallback endpoint made failures harder to diagnose.
---

Confirmed against JTL's official developer docs (developer.jtl-software.com) and a JTL support-forum report of real production use: the only endpoint JTL's on-premise Wawi REST API ("eazyBusiness") exposes for triggering a sales-order workflow event is:

```
POST /api/eazybusiness/v1/salesOrders/{salesOrderId}/workflowEvents
Body: {"Id": <workflowEventId>}
```

There is no `salesOrders/{id}/workflow/{workflowEventId}` route (that singular `/workflow/{id}` shape only exists for delivery notes, see the delivery-note-vs-carrier-field memory). A guessed fallback to that shape for sales orders always 404s with an empty body, and — worse — a "try primary, catch 400/404/405, fall back" pattern silently swallowed the primary endpoint's real failure and surfaced only the fallback's meaningless 404 instead.

**Why:** This caused a confusing runtime error (404 on `.../salesOrders/{id}/workflow/{id}`) that pointed at a non-existent route instead of showing why the real, correct endpoint call had actually failed.

**How to apply:** When adding a call to an external vendor API endpoint you have not confirmed against real docs/support reports, don't add a silent "try guessed shape, fall back to another guessed shape" pattern — it hides the real error. Verify the exact endpoint shape first, and if a fallback is genuinely needed, keep the original exception visible (or log it) rather than replacing it with the fallback's own failure.
