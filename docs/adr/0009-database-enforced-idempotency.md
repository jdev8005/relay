# ADR-0009: Database-Enforced Idempotency

- **Status:** Accepted
- **Date:** 2026-09-13

## Context

When a sender retries, the same event is submitted multiple times, sometimes
concurrently. Relay needs exactly-once storage from at-least-once delivery.

## Decision

A unique index on `(partner, external_id)`. The insert is attempted directly and
the resulting `UniqueConstraintViolationException` is caught, returning 200 with
a `duplicate` status in the response body.

The `duplicate` field is meant for internal observability rather than for the
sender's logic.

200 rather than 409 to avoid triggering a sender's retry logic and the possible
loop that follows. A 409 is semantically defensible, but many HTTP clients retry
on any non-2xx response, which would tell an aggressive sender to try again
indefinitely.

### Alternatives considered

**A `SELECT` to check record existence, followed by an `INSERT` if absent.**

Rejected because it is two statements with a gap between them. If two duplicates
of the same event are delivered concurrently, both can read "not found" and both
insert. The window is small but real, and an aggressively retrying sender is
precisely the thing that finds it.

## Consequences

**Positive**

- Correctness is enforced by the database rather than by application code, so no
  future refactor of the controller can reintroduce the race.
- It costs nothing — the insert happens either way.

**Negative / accepted risks**

- Correctness depends on the database engine in use. See ADR-0012.
- The approach requires the partner to send a stable event ID. If a stable ID is
  not sent, hashing the entire payload object would be the next best verification
  method, but weaker — a legitimate resend of changed data would look identical
  to a new event.
- `external_id` is capped at 191 characters, which a partner could eventually
  theoretically exceed. The cap exists to keep the index under MySQL's key length
  limit on `utf8mb4`.

## Future considerations

If a partner does exceed the 191-character limit, hashing the `external_id` and
storing that value will be the next evolution of this process. Hashing the ID is
deterministic, so idempotency still holds — this is a different technique from
hashing the payload described above, which is a weaker fallback for a different
problem.
