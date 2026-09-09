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
- **Decision** — what was chosen
- **Consequences** — what this bought, what it cost, what risk was accepted

Use [`template.md`](template.md) as the starting point for new records.

Statuses are `Proposed`, `Accepted`, `Superseded by ADR-NNNN`, or `Deprecated`.

## Records

| # | Title | Status |
|---|---|---|
| [0001](0001-dedicated-aws-account.md) | Dedicated AWS account for Relay | Accepted |
| [0002](0002-identity-center-for-human-access.md) | IAM Identity Center for human access | Accepted |
| [0003](0003-github-oidc-federation.md) | GitHub OIDC federation with asymmetric CI roles | Accepted |
| [0004](0004-s3-native-state-locking.md) | S3 native state locking over DynamoDB | Accepted |
| [0005](0005-customer-managed-kms-key-for-state.md) | Customer-managed KMS key for state encryption | Accepted |
| [0006](0006-budget-as-first-resource.md) | Budget as the first provisioned resource | Accepted |
| [0007](0007-wsl2-development-environment.md) | WSL2 as the development environment | Accepted |
