# Architecture Decision Records

This directory records the significant technical decisions made building Relay,
along with the reasoning and trade-offs behind each one.

An ADR captures a decision at the moment it was made — the context that forced
it, the options considered, and what was given up. Records are immutable once
accepted. When a decision changes, a new ADR supersedes the old one rather than
editing it, so the reasoning history stays intact.

## Format

Each record follows the same structure:

- **Context** — the situation that required a decision
- **Decision** — what was chosen, and what was rejected
- **Consequences** — what this bought, what it cost, what risk was accepted

Use [`template.md`](template.md) as the starting point for new records.

Statuses are `Proposed`, `Accepted`, `Superseded by ADR-NNNN`, or `Deprecated`.

## Records

### Infrastructure (Week 0)

| # | Title | Status |
|---|---|---|
| [0001](0001-dedicated-aws-account.md) | Dedicated AWS account for Relay | Accepted |
| [0002](0002-identity-center-for-human-access.md) | IAM Identity Center for human access | Accepted |
| [0003](0003-github-oidc-federation.md) | GitHub OIDC federation with asymmetric CI roles | Accepted |
| [0004](0004-s3-native-state-locking.md) | S3 native state locking over DynamoDB | Accepted |
| [0005](0005-customer-managed-kms-key-for-state.md) | Customer-managed KMS key for state encryption | Accepted |
| [0006](0006-budget-as-first-resource.md) | Budget as the first provisioned resource | Accepted |
| [0007](0007-wsl2-development-environment.md) | WSL2 as the development environment | Accepted |

### Application (Week 1)

| # | Title | Status |
|---|---|---|
| [0008](0008-accept-and-queue-webhook-ingestion.md) | Accept-and-queue webhook ingestion | Accepted |
| [0009](0009-database-enforced-idempotency.md) | Database-enforced idempotency | Accepted |
| [0010](0010-storing-raw-payloads.md) | Storing raw payloads | Accepted |
| [0011](0011-container-only-php-toolchain.md) | Container-only PHP toolchain | Accepted |
| [0012](0012-mysql-in-tests-rather-than-sqlite.md) | MySQL in tests rather than SQLite | Accepted |

## Where to start

For the architecture, read **0008** and **0009** — they describe the ingestion
guarantee the rest of the system is built around.

For the infrastructure, read **0003** — the OIDC trust model, including a
non-obvious finding about how GitHub's immutable repository IDs change the
`sub` claim format.

For an honest account of something that did not go smoothly, read **0011**.
