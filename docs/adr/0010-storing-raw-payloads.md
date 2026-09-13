# ADR-0010: Storing Raw Payloads

- **Status:** Accepted
- **Date:** 2026-09-13

## Context

The ingestion path writes the incoming webhook body to the database before any
interpretation. An alternative would be to parse the payload, extract the fields
Relay cares about, store those, and discard the rest.

Relay integrates with partners whose payload formats are outside its control and
subject to change without notice. A processing bug or an unannounced schema
change is not a hypothetical.

## Decision

Store the complete, unmodified payload in a `json` column. Parsing happens in the
queued job, against the stored copy.

### Alternatives considered

**Parse on ingest, store extracted fields.** Smaller storage footprint and a
schema that reflects the domain rather than the wire format. Rejected because
anything not extracted is gone permanently — including the fields a future bug
report would need.

## Consequences

**Positive**

- A processing bug is recoverable. Fix the job, replay the stored events.
- An audit trail of what a partner actually sent, distinct from what Relay
  believed they sent. This matters the first time a partner disputes a payload.
- New fields become usable retroactively. If a partner adds data Relay later
  needs, historical events already contain it.

**Negative**

- Storage grows with volume and never shrinks on its own.
- The stored payload is untyped. Nothing at the database level guarantees its
  shape, so every consumer must handle malformed or missing fields.

**Accepted risks**

- **Retention is unresolved.** At present the table grows without bound and
  nothing is ever deleted. That is acceptable for a portfolio project with
  synthetic traffic and is not acceptable for real partner data.

## Open question

Before this system handles production partner data, three things need answering:

1. **How long are raw payloads kept?** A time-based partition or archival to S3
   with a lifecycle policy is the obvious shape, and would align with the state
   bucket approach in ADR-0004.
2. **Do payloads contain personal data?** If a partner sends customer names,
   addresses, or payment identifiers, retention stops being a storage question
   and becomes a compliance one.
3. **Should stored payloads be encrypted at the column level?** The database is
   encrypted at rest, but that does not protect against an application-level
   read.

These are deferred rather than decided. Recording them here so the deferral is
deliberate rather than an oversight.
