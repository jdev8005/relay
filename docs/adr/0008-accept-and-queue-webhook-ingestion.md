# ADR-0008: Accept-and-Queue Webhook Ingestion

- **Status:** Accepted
- **Date:** 2026-09-13

## Context

I am unable to control partner webhook delivery, which includes timing, volume,
and duplicate events. A typical sender timeout is 5–10 seconds, with aggressive
retrying on any response other than a 2xx.

## Decision

Verify the signature of the submitted payload, persist the raw payload to a
database table, dispatch a queued job, and return 202 — alerting the requester
that the payload has been received successfully.

No business logic runs in the request path.

### Alternatives considered

**Processing the payload inline** — validating, looking up records, calling
downstream services, writing results, and returning 200.

Rejected because the work happens inside the sender's timeout window. A slow
downstream call blows that window, the sender retries, and the same event is
processed twice while the first attempt is still in flight.

## Consequences

**Positive**

- The handler completes in milliseconds regardless of any downstream latency,
  so sender timeouts stop being a factor.
- Storing the raw payload makes the process much more durable and allows for
  retrying a payload after debugging a downstream issue if necessary.

**Negative**

- The 202 response only tells the partner that the payload was received
  successfully, not that it has been processed. This requires a separate
  failure path.
- Debugging is more difficult because the actual work is being done elsewhere
  and not inline with the request.
- Introduces a dependency on Redis and on worker processes being healthy.

## Related

- ADR-0009: Database-enforced idempotency — the mechanism that handles duplicate
  deliveries. Accept-and-queue does not provide that guarantee on its own.
- ADR-0010: Storing raw payloads — the durability and replay argument in detail.
