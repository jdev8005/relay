# ADR-0006: Budget as the First Provisioned Resource

- **Status:** Accepted
- **Date:** 2026-08-31

## Context

Relay runs against a hard $25/month budget. The project provisions ECS Fargate,
networking, and managed services — a resource set where a single misconfiguration
can multiply the bill. A NAT Gateway alone is roughly $32/month, more than the
entire budget, and is easy to leave running after a partial destroy.

Cost controls are usually added after the first surprising invoice.

## Decision

Provision the budget in the `bootstrap` configuration, before any compute or
networking resource exists.

Three notification thresholds:

| Threshold | Type | Purpose |
|---|---|---|
| 50% | Actual | Early signal that burn rate is higher than expected |
| 80% | Actual | Act now |
| 100% | Forecasted | The one that matters |

The forecasted threshold is the substantive control. Actual-spend alerts report
money already gone. A forecasted alert fires on day 6 when the current burn rate
projects past $25 by month end, while the spend can still be prevented.

The budget lives in `bootstrap` rather than in the main stack. The main stack is
destroyed nightly; a budget that disappears with it is not a control.

## Implementation notes

### Cost Anomaly Detection is account-scoped and singular

A dimensional (`SERVICE`) anomaly monitor was included alongside the budget to
catch a different failure — a sudden spike early in the month that has not yet
crossed any percentage threshold.

Creating it failed:

```
ValidationException: Limit exceeded on dimensional spend monitor creation
```

AWS permits one dimensional anomaly monitor per account, and one already existed —
created automatically with the account. The configuration was changed to adopt the
existing monitor via data source rather than provision a duplicate.

This is a real distinction worth recording: some controls are properties of the
account, not of a project. Terraform configurations that assume they own
everything in an account break the first time they run somewhere that already has
history. Adopting a pre-existing resource is the normal case in any environment
that predates the code managing it.

### Billing access must be enabled by root

`aws_budgets_budget` fails with an opaque authorization error until
**IAM User and Role Access to Billing Information** is activated on the account.
That setting is visible only to the root login and cannot be set by an
administrator principal, so it cannot be Terraformed. It is a manual prerequisite,
documented in the Week 0 runbook.

### Cost allocation tag activation is not retroactive

`default_tags` in the provider block applies `Project`, `Environment`, and
`ManagedBy` to every taggable resource. Those tags must additionally be activated
in the Billing console before they can be used for cost allocation, and activation
does not apply to spend that already occurred. Untagged spend before activation
stays uncategorized permanently.

## Consequences

**Positive**

- A cost ceiling exists before anything can spend against it.
- The forecasted alert provides warning while the outcome is still preventable.
- Free — AWS Budgets includes two budgets per account, and Cost Anomaly Detection
  is free.
- Survives the nightly destroy cycle by living in `bootstrap`.

**Negative**

- Budgets evaluate roughly every 8–12 hours, so alerting is not real-time. A
  runaway resource can accrue most of a day's cost before notification.
- Notifications are email only. No automated action is taken.

**Accepted risks**

- No budget *action* is configured — nothing automatically stops spend. An action
  that detaches IAM policies or stops instances was considered and rejected as
  disproportionate for a portfolio project, where an unexpected shutdown mid-build
  costs more than the overspend it prevents.
- The alert path was verified by temporarily lowering the limit and confirming
  delivery. Without that test, the budget is an assumption rather than a control.
