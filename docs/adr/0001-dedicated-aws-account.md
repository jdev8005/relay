# ADR-0001: Dedicated AWS Account for Relay

- **Status:** Accepted
- **Date:** 2026-08-31

## Context

Relay needs an AWS account to build in. Three options existed: an existing
personal AWS account with unrelated resources in it, a shared account, or a new
account created for this project alone.

Two constraints shaped the choice.

Relay is designed to run a nightly destroy-and-rebuild cycle. That means
Terraform will issue bulk deletes on a schedule, unattended. Any resource in the
same account that Terraform believes it owns is at risk, and any state drift
becomes a deletion candidate.

The project also runs against a $25/month budget, and one of its deliverables is
a per-component cost breakdown. That analysis is only honest if every dollar in
the account belongs to this project.

## Decision

Create a new AWS account used exclusively for Relay, registered with a
plus-addressed email alias (`...+relay-aws@...`) so it does not require a
separate inbox.

Root is hardened immediately: MFA registered on two devices, no access keys,
alternate contacts set for billing, operations, and security. Root is then
retired from routine use.

Enabling IAM Identity Center creates an AWS Organization with this account as the
management account. That was accepted rather than avoided — it costs nothing and
leaves room to add a staging account later without restructuring.

### Alternatives considered

**Existing personal account.** Rejected on blast radius. A scheduled destroy
running against an account containing unrelated resources is an accident waiting
for a bad state file.

**Resource tagging and IAM boundaries within a shared account.** Technically
workable and closer to real multi-tenant practice, but it defends against
accidental deletion with policy rather than isolation. For a solo project the
extra complexity buys less than account separation does.

## Consequences

**Positive**

- Nightly destroy has a blast radius of exactly this project.
- Cost attribution is unambiguous; the budget and the project are the same thing.
- Free, and reversible — the account can be closed when the project ends.
- Produces a management account and an Organization, which is closer to how AWS
  is actually operated than a single standalone account.

**Negative**

- One more set of root credentials to secure.
- Cross-account access would need explicit setup if it ever became relevant.

**Accepted risks**

- Consolidated billing is not in play, so the Free Tier applies to this account
  independently. Not a concern at this budget, but worth noting if the account is
  ever folded into a larger organization.
