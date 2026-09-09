# ADR-0004: S3 Native State Locking over DynamoDB

- **Status:** Accepted
- **Date:** 2026-08-31

## Context

Terraform state must be stored remotely so that CI and the local operator work
against the same state, and it must be locked so that two concurrent applies
cannot corrupt it.

The long-standing pattern pairs an S3 bucket with a DynamoDB table: S3 holds the
state, DynamoDB holds a lock item written with a conditional put. Nearly every
tutorial and reference implementation still shows this.

That pattern predates S3 supporting strongly consistent conditional writes.

## Decision

Use the S3 backend with `use_lockfile = true` and no DynamoDB table. Locking is
handled by a `.tflock` object written to the same bucket using S3 conditional
writes.

Terraform 1.10 introduced this as experimental. Terraform 1.11 promoted it to
generally available and deprecated the `dynamodb_table` argument, which is
scheduled for removal in a future minor version. `required_version` is set to
`>= 1.11` accordingly.

State for both configurations lives in one bucket under separate keys —
`bootstrap/terraform.tfstate` and `prod/terraform.tfstate` — rather than in two
buckets.

### Alternatives considered

**S3 with a DynamoDB lock table.** The established pattern, and still what most
reference material shows. Rejected because it provisions a second resource with
its own IAM permissions, its own cost, and its own failure mode, to solve a
problem S3 now solves natively. Building it would mean implementing a deprecated
mechanism on a project whose purpose is demonstrating current practice.

**Terraform Cloud remote state.** Free at this scale and removes the bootstrap
problem entirely. Rejected because provisioning and securing the state backend is
part of what this project is meant to demonstrate.

## Implementation notes

Native locking depends on bucket versioning being enabled. Versioning is also the
recovery path if state is corrupted mid-apply.

Lock behaviour was verified by running `terraform plan -lock-timeout=0` in two
terminals concurrently. The second returns `412 PreconditionFailed` and names the
lock holder — the conditional write failing as designed.

The state bucket carries `prevent_destroy = true`. Relay runs a nightly
destroy-and-rebuild cycle, and the one bucket that must survive that cycle is
configured to refuse deletion even when explicitly asked.

A lifecycle rule expires noncurrent object versions after 90 days. Versioning
otherwise accumulates every state write indefinitely.

## Consequences

**Positive**

- One resource instead of two, with correspondingly fewer IAM permissions to
  grant the CI roles.
- No DynamoDB cost, however small.
- Uses the mechanism Terraform is standardizing on rather than the one it is
  removing.

**Negative**

- Requires Terraform 1.11 or newer, so the CI runner and local toolchain must
  stay in step. A version mismatch between local and CI is a real source of
  "works locally, fails in CI."
- Most published examples and much of the existing tooling still assume DynamoDB,
  so this configuration will look wrong to readers who last set up a backend a
  year ago.

**Accepted risks**

- Both configurations share one bucket. A bucket-level misconfiguration affects
  both. Accepted because separate buckets would double the KMS and policy surface
  without meaningfully reducing correlated risk in a single-account setup.
