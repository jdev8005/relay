# ADR-0002: IAM Identity Center for Human Access

- **Status:** Accepted
- **Date:** 2026-08-31

## Context

The operator needs administrative access to the Relay account from a local
development machine. The default path — create an IAM user, generate an access
key pair, write it to `~/.aws/credentials` — produces a long-lived credential
stored in plaintext on a laptop that also holds credentials for an unrelated
employer account.

Long-lived keys have no expiry, are not tied to a session, and leave no signal
when copied. Rotating them is manual and therefore rarely done.

## Decision

Use IAM Identity Center with a single user assigned an `AdministratorAccess`
permission set, scoped to a 4-hour session, authenticated via browser with MFA
required.

The AWS CLI is configured with `aws configure sso`, producing a `relay` profile
that holds no secret material. `~/.aws/credentials` contains no entry for this
account.

The Identity Center instance is configured as a **single-Region organization
instance** in `us-east-2`.

### Alternatives considered

**IAM user with MFA and no console password.** Still requires an access key for
CLI use, which is the thing being avoided.

**Multi-Region Identity Center instance.** Rejected on cost and relevance.
Multi-Region provisions a customer-managed multi-Region KMS key, roughly
$2/month across both Regions, to keep the access portal reachable during a
regional Identity Center disruption. With one user and no availability
requirement, that is 8% of the project budget for a property nobody needs.

**Account instance rather than organization instance.** Rejected because the
organization instance supports multi-account permission sets, leaving room for a
staging account without rebuilding identity.

## Implementation notes

Session duration was set to 4 hours. The 1-hour default forces re-authentication
mid-task; 12 hours weakens the benefit of short-lived credentials.

The `AWS_PROFILE` environment variable is scoped per-directory with `direnv`
rather than exported globally in `.bashrc`. A global export would apply to shells
opened in unrelated repositories, silently pointing AWS CLI commands at the wrong
account. Directory scoping makes the active account a property of location rather
than of what was last typed.

## Consequences

**Positive**

- No long-lived credential for this account exists anywhere on disk.
- Sessions expire on their own; there is nothing to rotate.
- Access is revocable centrally by removing the assignment.
- Produces an Organization and permission sets, both of which generalize to real
  multi-account environments.

**Negative**

- Requires a browser round trip to authenticate, which is friction on a headless
  or remote session.
- Under WSL2, the browser handoff depends on Windows interop and falls back to
  manual URL entry when interop is unavailable.

**Accepted risks**

- A single permission set grants `AdministratorAccess`. Appropriate for a
  single-operator project, but a real environment would scope permission sets by
  role and reserve administrative access for break-glass use.
- Credentials for an unrelated employer account remain in `~/.aws/credentials`.
  The `[default]` profile was renamed to prevent Terraform from silently falling
  back to it, and `allowed_account_ids` in the provider block enforces the
  correct account regardless. See ADR-0003.
