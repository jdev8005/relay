# ADR-0003: GitHub OIDC Federation with Asymmetric CI Roles

- **Status:** Accepted
- **Date:** 2026-08-31

## Context

The CI pipeline needs AWS credentials to run `terraform plan` on pull requests
and `terraform apply` on merge to `main`. Relay's repository is public.

The conventional approach stores an IAM user's access key pair as repository
secrets. That credential is long-lived, has no session boundary, and is available
to every workflow in the repository. On a public repository it is also one
misconfigured workflow trigger away from being usable by a fork.

## Decision

Register GitHub Actions as an OIDC identity provider in the account and federate
into two IAM roles. No AWS credential is stored in the repository. The only
repository secret is the account ID, which is not a credential — it appears in
every ARN.

The two roles are deliberately asymmetric:

| | `relay-gha-plan` | `relay-gha-apply` |
|---|---|---|
| Permissions | `ReadOnlyAccess` + state access | `PowerUserAccess` + scoped IAM + state access |
| Trust condition | `StringLike` on `repo:OWNER/relay:*` | `StringEquals` on `repo:OWNER/relay:environment:production` |
| Assumable from | any branch, any pull request | the `production` environment only |

### Why the apply role is scoped to an environment, not a branch

Scoping apply to `ref:refs/heads/main` would mean anyone able to merge to `main`
can apply. Scoping to `environment:production` means GitHub only issues a token
carrying that `sub` claim after the environment's protection rules are satisfied —
in this case, a required reviewer approval.

The effect is that the human approval gate lives in GitHub, and AWS refuses to
issue credentials without it. The two systems enforce different properties: AWS
guarantees only production-environment jobs receive write credentials; GitHub
guarantees a human approved before that job ran.

"Allow administrators to bypass configured protection rules" is disabled. With it
enabled, a repository admin can click through their own gate, which makes the
control advisory rather than real.

### Guarding against wildcard drift

The apply role uses `StringEquals`, not `StringLike`. A trailing `*` on that
condition — `repo:OWNER/relay:*` — would permit any workflow in the repository,
including one introduced by a fork's pull request, to assume the write role. This
is the most common misconfiguration in published OIDC examples.

### Permission shape of the apply role

`PowerUserAccess` excludes IAM entirely, but Terraform legitimately needs to
create ECS task and execution roles. The gap is closed with an explicit allow for
role and policy management actions, paired with two explicit denies:

- `DenyIdentityCreation` blocks `iam:CreateUser`, `iam:CreateAccessKey`, and
  login profile actions. Nothing in this project should ever mint a long-lived
  credential.
- `DenyBootstrapTampering` blocks the apply role from modifying its own or the
  plan role's trust policy.

Explicit deny beats any allow in IAM evaluation, so both hold under
`PowerUserAccess`.

## Implementation notes

The `thumbprint_list` on `aws_iam_openid_connect_provider` is a placeholder value.
AWS stopped validating thumbprints for GitHub's OIDC endpoint in 2023 and
validates against the endpoint's root CA instead, but the Terraform provider still
requires the argument to be present.

### The `sub` claim format is not fixed

The trust policy initially matched `repo:OWNER/REPO:*`, the format shown in
essentially all published examples. Every component verified correct in
isolation — provider URL, client ID list, role ARN, region, `id-token: write`
permission, account ID secret — and `sts:AssumeRoleWithWebIdentity` still
returned `Not authorized`.

Decoding the actual token revealed why:

    "sub": "repo:jdev8005@233085440/relay@1349205329:pull_request"

The repository has immutable IDs enabled, which embeds the numeric owner ID and
repository ID into the `sub` claim. Names can be transferred, deleted, and
re-registered by someone else; numeric IDs cannot. Pinning trust to the numeric
form removes a class of attack where a policy referencing a relinquished
username is later satisfied by a different account.

The trust conditions were updated to construct the subject from both name and ID:

    repo:${owner}@${owner_id}/${repo}@${repo_id}

The failure mode is worth recording: the error is identical whether the OIDC
provider, the audience, the role ARN, the account ID, or the subject is wrong.
Inspecting each component individually cannot distinguish them, because each one
is individually correct. The only diagnostic that resolves it is decoding the
token GitHub actually sent, by requesting it from
`ACTIONS_ID_TOKEN_REQUEST_URL` and base64-decoding the payload segment.

The workflow requires `id-token: write` in its `permissions` block. Without it,
no OIDC token is minted and the credentials step fails for an unrelated-looking
reason.

`terraform` and `bootstrap` are separate configurations. Both CI roles are granted
access to the state bucket and its KMS key; see ADR-0005 for the key policy.

### Two OIDC failure modes that could get confused
Could not load credentials from any providers means GitHub never minted a
token — the job is missing id-token: write in its permissions block. This
is a GitHub-side configuration problem; AWS was never contacted.

Not authorized to perform sts:AssumeRoleWithWebIdentity means a token was
issued and AWS rejected it — a trust policy mismatch on the sub or aud
claim. This is an AWS-side problem.

## Consequences

**Positive**

- No AWS credential exists in the repository or in GitHub secrets.
- Credentials are session-scoped and expire automatically.
- Read and write paths have genuinely different trust boundaries, not just
  different policies.
- The approval gate is enforced by credential issuance rather than convention.

**Negative**

- More moving parts than a static key. Failures surface as a single opaque STS
  error regardless of which of four components is misconfigured.
- Trust policy changes require a Terraform apply, so debugging has a slow loop.

**Accepted risks**

- The plan role holds `ReadOnlyAccess` account-wide and is assumable from any
  pull request, including from forks if fork PRs are ever enabled. Read-only on a
  portfolio account is an acceptable exposure; on an account with real data it
  would not be, and the trust condition would need to exclude
  `pull_request_target` and fork events explicitly.
- `PowerUserAccess` on the apply role is broad. Narrowing it to the specific
  services Relay uses is deferred until the resource set stabilizes.
- The trust policy hard-codes numeric owner and repository IDs. Recreating the
  repository under the same name produces a new repository ID and breaks
  authentication until the policy is updated. This is the intended trade-off —
  a name-based policy would keep working, which is exactly the property that
  makes it weaker.
