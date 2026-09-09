# ADR-0007: WSL2 as the Development Environment

- **Status:** Accepted
- **Date:** 2026-08-31

## Context

Development happens on Windows 11. Relay is a Dockerized Laravel application
deployed to ECS Fargate, with CI running on GitHub's `ubuntu-latest` runners.

Terraform, the AWS CLI, and Docker all run natively on Windows, so a native
PowerShell toolchain was viable.

## Decision

Develop inside WSL2 (Ubuntu 24.04), with the repository stored on the Linux
filesystem under `~/code/relay` rather than under `/mnt/c/`.

Docker Desktop provides the container runtime with WSL integration enabled. VS
Code connects through the WSL extension, giving a Windows-side editor with a
Linux-side backend.

### Reasoning

**Filesystem performance.** Docker Desktop on Windows uses the WSL2 backend
regardless. Bind-mounting source from the Windows filesystem into a Linux
container crosses a translation layer on every file operation. The cost is
tolerable for a small project and compounds as the application grows.

**Line endings.** Terraform tolerates CRLF. Shell scripts and container
entrypoints do not. A `.sh` file with CRLF fails inside a container with
`bad interpreter: No such file or directory` — an error that gives no indication
of its cause. Developing on Linux removes the class of problem rather than
managing it.

**Parity with CI.** The pipeline runs on `ubuntu-latest`. Every difference
between the local environment and the runner is a potential "works locally, fails
in CI." Same OS family, same shell, same path semantics.

**Ecosystem fit.** `tfenv` does not run natively on Windows, so Terraform version
pinning would be manual — and a version mismatch between local and CI is exactly
the failure the previous point describes. Documentation, examples, and error
messages across this toolchain assume bash.

## Implementation notes

Defense in depth on line endings, since the Windows editor can still write CRLF:

- `.gitattributes` with `* text=auto eol=lf`, committed so it applies to anyone
  cloning.
- A `mixed-line-ending` pre-commit hook with `--fix=lf`.

`core.autocrlf` was deliberately **not** set globally. Global git configuration
bleeds into unrelated repositories on the same machine, including work
repositories with their own conventions. Repository-scoped `.gitattributes`
achieves the same result without that reach.

Git identity is likewise set per-repository rather than globally, so commits to a
public portfolio repository do not carry an employer email address.

`AWS_PROFILE` is scoped with `direnv` rather than exported in `.bashrc`, for the
same reason — see ADR-0002.

### Known friction

WSL interop failures produce a warning when a Linux process tries to launch a
Windows browser, affecting `gh auth login` and `aws sso login`. Both fall back to
printing a URL and code for manual entry, so the workflows still complete. On
current WSL builds the interop handler registers as `WSLInterop-late`, which some
tools probe for under the older `WSLInterop` name — the warning is cosmetic and
the launch succeeds on retry.

WSL's clock can drift after the host sleeps, causing AWS to reject signed requests
with `SignatureDoesNotMatch`. Resolved with `sudo hwclock -s`.

## Consequences

**Positive**

- Local environment matches the CI runner and the production container OS.
- Container filesystem performance is materially better than the alternative.
- An entire class of line-ending bugs is prevented rather than debugged.
- Standard Linux tooling installs and behaves as documented.

**Negative**

- An additional layer between the editor and the runtime, with its own failure
  modes (interop, clock drift, Docker integration toggles).
- Two separate `~/.aws` directories exist if the AWS CLI is installed on both
  Windows and WSL, with independent SSO caches. Only the WSL installation is used.

**Accepted risks**

- WSL2 is not a production environment and its behaviour diverges from a real
  Linux host in areas including networking and systemd. Adequate for development;
  the deployment target remains Fargate.
