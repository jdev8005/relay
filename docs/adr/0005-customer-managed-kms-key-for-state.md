# ADR-0005: Customer-Managed KMS Key for State Encryption

- **Status:** Accepted
- **Date:** 2026-09-01

## Context

Terraform state for both configurations lives in a single S3 bucket. State files
contain resource identifiers, ARNs, full policy documents, and potentially secret
material carried in resource attributes.

The bucket was initially configured with SSE-S3 (`AES256`). A Trivy pre-commit
scan flagged AWS-0132 (HIGH): the bucket does not encrypt data with a
customer-managed key.

The first assessment was to suppress the finding. A CMK costs roughly $1/month
against a $25 budget, and the threat it mitigates — a principal holding S3 read
access but lacking KMS decrypt permission — is largely theoretical in a
single-account, single-principal environment.

That assessment was revised on a different question: not "is the risk real here"
but "would this key be built anyway." Relay will provision a KMS key regardless,
for ECS task definitions and Secrets Manager storage of application credentials.
Given the key is already on the roadmap, encrypting state with it is marginal
cost rather than new cost.

## Decision

Encrypt Terraform state with a customer-managed KMS key (`alias/relay-tfstate`),
with automatic rotation enabled and `bucket_key_enabled = true`.

### Dual-layer authorization

KMS evaluates the key policy **and** the caller's IAM policy. Unlike most AWS
services, a grant in one does not substitute for the other. Both were configured:

- **Key policy** — `kms:Decrypt`, `kms:GenerateDataKey`, `kms:DescribeKey` for
  the two CI roles, plus full `kms:*` for the account root principal.
- **IAM policy** — the same three actions scoped to the key ARN, attached to both
  CI roles.

The account-root statement is mandatory, not boilerplate. A KMS key whose policy
does not grant the account administrative access is permanently unmanageable, and
AWS cannot recover it.

### Cost control

`bucket_key_enabled = true` collapses per-object KMS calls into a single call per
S3 bucket key, cutting KMS request charges by roughly two orders of magnitude.
Without it, every state read and write incurs a billable KMS request. This setting
is what makes the cost defensible at this budget.

## Implementation notes

### Ordering constraint

The change had to be sequenced carefully, because the bucket being modified holds
the state Terraform must read in order to make the modification.

1. Create the key and grant both CI roles access via key policy and IAM policy.
2. Only then switch the bucket's default encryption to `aws:kms`.

Reversing this order locks every principal out of state on the next read.

### S3 does not re-encrypt existing objects

Changing bucket default encryption applies only to subsequent writes. Objects
written before the change retain their original encryption, including all prior
versions. The lifecycle rule from ADR-0004 handles eventual cleanup.

### Backend `encrypt = true` overrides bucket defaults

This was the non-obvious failure, and it cost the most time to diagnose.

With `encrypt = true` and no `kms_key_id`, the Terraform S3 backend sends an
explicit `x-amz-server-side-encryption: AES256` header on every state write. An
explicit header on `PutObject` takes precedence over the bucket's default
encryption configuration.

The result was that `get-bucket-encryption` correctly reported the CMK, the
Terraform configuration was correct, and yet every state object written came back
`AES256`. The bucket default was never wrong. Terraform was explicitly asking for
something else, and S3 was honoring the request exactly as specified.

Both backend blocks now set `kms_key_id` alongside `encrypt = true`. This applies
**per client** — `bootstrap` and `terraform` are separate Terraform clients
writing to the same bucket, and each required the change independently. Fixing one
produces a bucket where half the state is encrypted as intended, which is worse
than a uniform failure because it looks resolved.

### Bucket defaults are a fallback, not a policy

Following from the above: default encryption applies only when a request does not
specify its own. Guaranteeing CMK encryption regardless of client behaviour
requires a bucket policy denying `s3:PutObject` when
`s3:x-amz-server-side-encryption-aws-kms-key-id` does not match the key ARN.

Not implemented here — the only writers are two CI roles and one local operator,
all under this project's control. Recorded as the correct mechanism if the bucket
ever accepts writes from principals outside that set.

## Consequences

**Positive**

- A second authorization layer independent of the bucket policy.
- Automatic annual key rotation.
- CloudTrail records every decrypt operation against state.
- Resolves AWS-0132 by implementing the control rather than suppressing the
  finding.

**Negative**

- ~$1/month for the key, roughly 4% of the project budget.
- Any new principal needing state access requires grants in two places.
- A misconfigured key policy locks out state access, with no recovery path if the
  account-root statement is ever removed.

**Accepted risks**

- AWS-0089 (S3 server access logging) remains suppressed in `.trivyignore.yaml`
  with an expiry of 2027-03-01. Access logging requires a second bucket with its
  own cost and its own recursive logging question; CloudTrail S3 data events are
  the appropriate audit mechanism for this bucket.
- State objects written before the change retain SSE-S3 encryption. Accepted
  because this is defense in depth rather than remediation of a known exposure.

## References

- Trivy AWS-0132 — https://avd.aquasec.com/misconfig/aws-0132
- ADR-0004: S3 native state locking over DynamoDB
