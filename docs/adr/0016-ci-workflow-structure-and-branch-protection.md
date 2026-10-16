# ADR-0016: CI Workflow Structure and Branch Protection

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Through Week 1, a single workflow file (`terraform.yml`) held four unrelated
jobs: `tests`, `plan`, `apply`, and later `build`. Every push ran all of them,
so Terraform planned against AWS on a PHP-only change and the PHP suite ran on
a Terraform-only change.

The repository is public. Nothing enforced that a change passed CI before
reaching `main`, so a broken workflow or failing test suite could be merged by
the only committer without anything stopping it.

CI needs AWS credentials for three purposes with very different risk:

- **Reading** infrastructure to produce a plan, on any branch or pull request.
- **Pushing** a container image to ECR (ADR-0015), on `main` only.
- **Changing** infrastructure, which should require a human approval.

ADR-0003 established OIDC federation with separate plan and apply roles. Week 2
added the image build, which needed a third trust boundary.

GitHub's required status checks match on job names and expect every required
check to report. A check that never runs is treated as pending, not passed

## Decision

**1. Two workflows, split by what triggers them.**

- `app.yml`: `changes` → `tests` → `build` → `app-gate`
- `terraform.yml`: `changes` → `plan` → `apply` → `terraform-gate`

**2. Three OIDC roles, one per risk level.**

| Role              | Trust condition (`sub`)                 | Permissions                                            |
| ----------------- | --------------------------------------- | ------------------------------------------------------ |
| `relay-gha-plan`  | `StringLike` any ref (`:*`)             | `ReadOnlyAccess`                                       |
| `relay-gha-build` | `StringEquals` `ref:refs/heads/main`    | ECR push to `relay-app` + KMS on the state key         |
| `relay-gha-apply` | `StringEquals` `environment:production` | `PowerUserAccess` + scoped IAM, behind manual approval |

The build role has no environment gate. Building is not deploying: an image in
ECR is inert until something runs it, and the immutable `sha-<commit>` tag
(ADR-0015) means a pushed image cannot later be swapped. Requiring approval for
every build would add friction without reducing risk.

**3. Gate jobs are the only required checks.** `app-gate` and `terraform-gate`
depend on every other job in their workflow, run with `if: always()`, and fail
explicitly if any dependency's result is `failure` or `cancelled`. Skipped
dependencies are treated as success.

**4. Path filtering happens inside the run, not on the trigger.** Both
workflows run on every pull request and every push to `main`. A `changes` job
using `dorny/paths-filter@v3` outputs whether relevant files changed, and
downstream jobs use `if: needs.changes.outputs.<name> == 'true'`. Jobs skip;
workflows always start, and the gate always reports.

**5. `main` is protected by an active repository ruleset (`protect-main`).**
Pull request required, squash merge only, linear history, force pushes and
deletion blocked, no bypass actors, and exactly two required checks:
`app-gate` and `terraform-gate`.

**6. `actionlint` runs in pre-commit**, so workflow syntax errors and invalid
`needs:` references are caught before commit rather than after push. Runners
are pinned to `ubuntu-24.04` rather than `ubuntu-latest`.

### Alternatives considered

- **One workflow, no path filtering.** Simplest and deadlock-free, but every
  pull request runs everything, including an AWS plan on a documentation change.
  Rejected as wasteful and noisy, and it doesn't reflect how production
  repositories are structured.
- **Trigger-level `paths:` filters with each job as a required check.** The
  first implementation. Rejected because it deadlocks: see Implementation notes.
- **Build job using the apply role behind the `production` environment.** Fewer
  roles, but every merge would wait for a manual approval just to produce an
  image. Rejected: approval belongs on the action that changes what is running,
  which is the deploy in Week 4, not the build.
- **GitHub merge queue.** Solves the same class of problem for high-traffic
  repositories by testing merges in sequence. Rejected as unnecessary for a
  single committer.
- **Classic branch protection rules.** Still supported, but rulesets are the
  current mechanism, can be layered, and are visible to non-admins. Rejected in
  favour of rulesets.

## Implementation notes

**The gate design emerged in three layers, each fixing the failure the
previous one exposed.**

1. *Trigger-level filters plus required checks deadlock.* With `paths:` on the
   trigger, a pull request touching only PHP never starts `terraform.yml`. Its
   jobs never report, GitHub treats them as pending forever, and the pull
   request cannot merge.
2. *Gate jobs need both halves of their condition, and missing either one fails
   open.* GitHub counts a skipped job as passing a required check: `success`,
   `skipped`, and `neutral` all satisfy it. Without `if: always()`, a failed
   `tests` job causes the gate to be skipped, the skipped gate counts as passing,
   and broken code can merge. Without the explicit failure check, the gate runs
   but has nothing to fail on, so it passes anyway. Each half closes the same
   hole from a different side.
3. *A gate can't report if its workflow never starts.* A documentation-only pull
   request matched neither workflow's trigger filter, so neither gate ran and the
   pull request hung. Moving filtering into a `changes` job fixed it. The general
   rule: **trigger-level path filters and required status checks cannot
   coexist.**

**`dorny/paths-filter` outputs are strings.** `if: needs.changes.outputs.app`
is always true, because `'false'` is a non-empty string. The comparison must be
`== 'true'`. When a job skips, every job that `needs:` it also skips unless it
uses `always()`, which is why the gate must list `changes` in its own `needs:`.

**Splitting the file dropped `id-token: write`.** The `plan` job failed with
`Could not load credentials from any providers`. This is distinct from
`Not authorized to perform sts:AssumeRoleWithWebIdentity`:

| Error                            | Meaning                              | Look at            |
| -------------------------------- | ------------------------------------ | ------------------ |
| `Could not load credentials`     | GitHub never minted an OIDC token    | Job `permissions:` |
| `Not authorized ... WebIdentity` | AWS received a token and rejected it | Role trust policy  |

**A run that fails in 0s and is labelled with a file path is a parse error.**
Two occurred:

- YAML merge keys (`<<: *db`) are not supported by GitHub Actions, even though
  they are valid YAML. Shared environment variables are written out per job.
- A leftover `build` job in `terraform.yml` still declared `needs: tests`. A job
  cannot depend on a job in a different workflow file.

**A broken workflow on the default branch mislabels runs on every branch.** The
workflow's display name is read from the default branch's copy, so a parse
failure there shows the file path instead of the name everywhere.

**A path filter naming a nonexistent file fails silently.** After the rename, a
filter still listed `.github/workflows/infra.yml`. It is a valid glob matching
nothing, so the workflow stopped re-running on changes to itself. `actionlint`
does not check glob targets.

**Gates protect nothing until they are required.** Pull requests #5 and #6
merged with broken workflows because the gates existed but no rule required
them.

**Rulesets are created `Disabled` by default.** `protect-main` was fully
configured and enforced nothing until its enforcement status was set to
`Active`. The reliable check is
`gh api repos/{owner}/{repo}/rules/branches/main`, which returns only rules that
are actually in effect.

**The build role worked on its first run** because its trust policy reused
`local.repo_sub`, which already uses the immutable numeric owner and repository
IDs found in ADR-0003.

## Consequences

**Positive** —
**Positive**

- No pull request can deadlock: every pull request gets exactly two gate
  results, whatever it touches.
- Unrelated work is skipped, so a PHP change does not call AWS and a Terraform
  change does not start MySQL.
- Each job holds only the credentials its purpose requires, enforced by AWS
  trust policies rather than workflow conventions.
- Image builds run without waiting for a human; approval is reserved for
  changes to infrastructure.
- `main` cannot receive a change that has not passed CI, including from the
  repository owner.
- Workflow syntax errors are caught locally before commit.

**Negative** —
- Both workflows start on every pull request. On a public repository Actions
  minutes are free; on a private one the `changes` and gate jobs would cost a
  few seconds of billed time each.
- `dorny/paths-filter` is third-party code that runs with the repository token.
- Ruleset configuration lives in the GitHub UI, not in the repository, so
  changes to it do not appear in any diff or review.
- Required check names are coupled to job `name:` values. Renaming a gate
  without updating the ruleset blocks every pull request.
- More moving parts than a single workflow file, and failures can occur in the
  gate logic itself.

**Accepted risks** —
- **`dorny/paths-filter@v3` is pinned to a mutable tag.** A compromised tag
  could run arbitrary code with repository access. Revisit by pinning to a
  commit SHA and adding the `github-actions` ecosystem to Dependabot.
- **Branch protection is not version-controlled.** Revisit by managing the
  ruleset with the GitHub Terraform provider.
- **No bypass actors.** If CI breaks such that no pull request can pass, the
  escape hatch is to add the owner to the bypass list, merge the fix, and remove
  the bypass immediately. This is deliberate and leaves an audit trail.
- **A `changes` job failure fails the gate.** An outage of the GitHub API blocks
  merges rather than allowing unverified changes through. Failing closed is the
  intended direction.
- **The two workflows are independent.** This holds while applying
  infrastructure and building images are unrelated. Revisit in Week 4, when
  deploying a new image becomes an infrastructure change and the workflows need
  ordering.

## Related
- ADR-0003: GitHub OIDC federation (plan and apply roles, immutable `sub` IDs)
- ADR-0011: Container-only PHP toolchain (mirrored by the `tests` job)
- ADR-0015: Container registry and image supply chain (target of the build role)
