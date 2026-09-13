# ADR-0012: MySQL in Tests Rather Than SQLite

- **Status:** Accepted
- **Date:** 2026-09-13

## Context

Laravel's default `phpunit.xml` points the test suite at SQLite, usually
in-memory. It is fast, requires no running service, and works well for the
majority of application tests.

Relay's central correctness claim is the idempotency guarantee in ADR-0009,
which is enforced by a MySQL unique index. Its payloads are stored in a `json`
column. Both are areas where SQLite and MySQL differ materially:

- **Unique constraint behaviour under concurrency.** SQLite serializes writes at
  the file level; MySQL/InnoDB uses row-level locking. The race the design
  protects against does not exist in the same form under SQLite.
- **JSON columns.** MySQL has a native `JSON` type with validation and query
  support. SQLite stores JSON as text with no validation.
- **Type affinity.** SQLite is dynamically typed and will silently accept values
  MySQL rejects — including values that would violate a column definition.

Testing these properties against SQLite tests something adjacent to production
behaviour rather than the behaviour itself.

## Decision

Point the test suite at MySQL, in a separate `relay_test` database, both locally
and in CI.

`phpunit.xml` sets `DB_CONNECTION=mysql`. Connection details come from the
environment, so the same configuration works against the Compose service locally
and the GitHub Actions service container in CI.

### Alternatives considered

**Keep SQLite, accept the divergence.** Substantially faster and simpler.
Rejected because the properties most likely to diverge are exactly the ones
under test.

**SQLite for unit tests, MySQL for feature tests.** A reasonable split in a
larger suite. Deferred — the current suite is small enough that the added
configuration complexity outweighs the time saved.

## Implementation notes

The SQLite default was not an informed choice; it was inherited and went
unnoticed. It surfaced only because a stack trace happened to print the
connection name:

```
SQLSTATE[23000]: ... (Connection: sqlite,
Database: /var/www/html/database/database.sqlite ...)
```

Nothing in the scaffolding announced it, and the tests had been passing. This is
the same pattern documented in ADR-0011 — a silently accepted default, visible
only when something unrelated failed.

Worth noting that the tests *were* passing against SQLite. The duplicate-delivery
test went green on an engine whose constraint semantics differ from production's.
A passing test against the wrong engine is worse than no test, because it
produces confidence rather than doubt.

Local and CI hosts differ: `mysql` (the Compose service name) versus `127.0.0.1`
(GitHub maps service containers to localhost). Keeping the host in the
environment rather than hard-coded in `phpunit.xml` is what allows one
configuration to serve both.

## Consequences

**Positive**

- The idempotency guarantee is verified against the engine that enforces it in
  production.
- JSON column behaviour, constraint semantics, and type strictness all match the
  deployment target.
- CI exercises a real database service, which is closer to how the application
  actually runs.

**Negative**

- The suite requires a running MySQL service. It cannot be run with `php artisan
  test` alone on a machine with nothing else started.
- Materially slower than in-memory SQLite — roughly twice the duration at current
  suite size, and the gap widens as the suite grows.
- CI job runtime increases by the container startup and health check.

**Accepted risks**

- Test runtime will eventually become a friction point. When it does, the answer
  is the unit/feature split deferred above, not a return to SQLite for the tests
  that matter.
