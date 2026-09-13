# ADR-0011: Container-Only PHP Toolchain

- **Status:** Accepted
- **Date:** 2026-09-13

## Context

Development happens on Windows 11 under WSL2. The deployment target is a
PHP container on ECS Fargate. CI runs on GitHub's `ubuntu-latest` runners.

The conventional setup installs PHP and Composer on the development machine and
uses containers only for services. That produces two PHP environments with
independently managed extension sets, and only one of them is the deployment
target. Divergence between them surfaces as "works locally, fails in CI" — a
category of bug that is expensive to trace because the code is identical and the
environment is not.

The first attempt at Week 1 hit this immediately: `composer create-project`
failed on a missing `ext-xml` that the container image already had.

## Decision

No PHP or Composer on the development host. Every PHP command runs inside the
container, including scaffolding.

The Dockerfile's `docker-php-ext-install` line is the authoritative
specification of the runtime. There is no second list to keep in sync.

### Alternatives considered

**Host PHP alongside the container.** Faster per command, better IDE integration
out of the box. Rejected because it reintroduces exactly the divergence this
project is meant to eliminate, and because the host environment is the one that
does not ship.

## Implementation notes

The decision is correct and was more expensive to implement than expected. Four
separate failures, each surfacing several layers from its cause.

### Scaffolding resolves against the wrong PHP

`composer create-project` was run in a throwaway `composer:2` image. That image
ships its own PHP, and Composer resolved Laravel's requirements against *that*
version rather than against the Dockerfile's. The result was a Laravel installed
for PHP 8.4 running on a `php:8.3-fpm-alpine` base.

The symptom was a parse error inside framework code:

```
In Request.php line 117:
  syntax error, unexpected token "{", expecting "," or ";"
```

That is PHP 8.3 encountering property hooks. Nothing in the message points at a
version mismatch, and the file named is vendor code the developer never touched.

The fix is pinning in both directions — `FROM php:8.4-fpm-alpine` in the
Dockerfile, and `config.platform.php` in `composer.json` so that Composer
resolves against the runtime regardless of which container runs the command.

### Non-interactive scaffolding silently accepts defaults

Container-run scaffolding has no TTY, so every installer prompt is answered by
its fallback. Laravel's testing-framework prompt defaulted to PHPUnit, and Pest
was never installed.

This surfaced two stages later as `Call to undefined function uses()` in a test
file — a failure with no visible connection to a prompt that was never shown.

### Root-owned files through the bind mount

The container runs as root by default. Every file `artisan make:*` generated
landed root-owned on the host, breaking the editor and every subsequent command.

Fixed with `ARG UID` / `ARG GID` build arguments and a `USER` directive in the
`dev` stage, with the values passed from `docker-compose.yml`. The `prod` stage
wants a non-root user for security reasons independently, so one change serves
both.

### The non-root user needs a writable HOME

The user was initially created with `adduser -H` (no home directory). Tools that
expect a writable `$HOME` then fail or warn — Psysh refused to start with
`Writing to directory /home/app/.config/psysh is not allowed`, and Composer's
cache had nowhere to live.

Fixed with `-h /home/app`, an explicit `mkdir`, a `chown`, and `ENV HOME`.

### Framework defaults shifted under a minimal install

Related, though not strictly caused by the container decision: Laravel 12 ships
without `routes/api.php` and without Horizon. Both are opt-in. Combined with
non-interactive scaffolding, the project was missing pieces that most
documentation assumes are present — producing a 404 on a route that had been
written, and `Command "horizon" is not defined` for a package assumed installed.

## The generalizable finding

Container-only tooling means every scaffolding step runs non-interactively, and
resolves dependencies against whatever image happens to run it. Defaults are
chosen silently, by a process with no visibility into what the runtime will
actually be.

The consistent failure signature is a symptom several layers removed from its
cause: a parse error in vendor code, an undefined function in a test file, a 404
on a route that exists. None of them name the decision that produced them.

**The practice this argues for:** immediately after scaffolding in a container,
enumerate what is actually present rather than trusting documented defaults.
Check the resolved PHP version, check which testing framework was installed,
check which optional packages exist. Five minutes of verification against a day
of misdirected debugging.

## Consequences

**Positive**

- One PHP environment. The Dockerfile's extension list is the single contract
  across local, CI, and production.
- The PHP that runs a command is byte-identical to the PHP that ships.
- The non-root user required for local file ownership is also the security
  posture wanted in production.

**Negative**

- Container overhead on every command. `docker compose exec app php artisan ...`
  is meaningfully slower than a host binary, and the verbosity encourages
  shortcuts. Mitigated with a `Makefile`.
- IDE tooling requires explicit configuration. Intelephense resolves vendor
  packages from the host filesystem by default and needs the VS Code Dev
  Containers extension to see the container's environment.
- Queue workers hold code in memory. Every change to a job class requires
  `docker compose restart horizon`, or the worker silently runs stale code —
  succeeding while changing nothing.

**Accepted risks**

- **PHP 8.4 is declared in three places** — the Dockerfile `FROM`, the
  `config.platform.php` entry in `composer.json`, and `php-version` in the CI
  workflow — with nothing enforcing that they agree. This is the residual
  weakness of the approach: host PHP was eliminated, but CI still installs its
  own. A container-based CI job using the same image would close the gap, at the
  cost of a slower pipeline. Deferred.
