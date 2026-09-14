# ADR-0005: Hand-written data layer, dialect-aware schema builder, plain-PHP migrations

- Status: accepted
- Date: 2026-09-14
- Deciders: engineering agent (design); product owner decided the database family provisionally (`O-2`: MySQL-compatible, `utf8mb4`, must also run on SQLite for local work)
- Rule reference: [database](../../../.agents/rules/database.md), [backend](../../../.agents/rules/backend.md), [security](../../../.agents/rules/security.md)
- Related open decisions: `O-2` (engine, provisional), `O-3` (PHP version, provisional), `O-20` (host facts, unanswered)

## Context

Phase 0 needs persistence before any product feature can be built. The constraints that shape it:

- no framework and no runtime dependency beyond PHP and the database (`C-4`, `C-9`, ADR-0002), which
  rules out an ORM or query-builder package;
- shared hosting: no shell access, no migration tool that must be installed, no build step;
- the owner has **no real host yet** and will test on localhost (XAMPP/Laragon/`php -S`), so the
  layer must run on SQLite as well as on the MySQL family without code changes;
- Persian text, emoji and full Unicode must survive storage, which makes `utf8mb4` and index key
  limits a real design constraint rather than a footnote;
- mistakes here are the expensive kind: a leaked SQL fragment, an unintentional `DELETE` without a
  condition, or a schema that only exists on one engine.

## Decision

1. **One `Connection` class wrapping PDO.** All access goes through it; there is no second way to
   reach the database anywhere in the codebase. One connection per request, created by
   `Application::database()`.
2. **Every query is parameterized.** The public API is `select/first/scalar/execute/insert/update/
   delete`; none of them accepts interpolated SQL. Identifiers (table and column names) are not
   parameters in SQL, so they are validated against `^[A-Za-z_][A-Za-z0-9_]*$` and quoted per
   dialect before use - never concatenated from user input.
3. **Destructive-by-omission is impossible.** `update()` and `delete()` refuse an empty condition
   argument, so "forgot the WHERE" cannot silently wipe a table.
4. **Dialect differences are confined to exactly two places**: `TableDefinition` (rendering column
   and constraint SQL) and `Connection` (connection setup and identifier quoting). Application code
   never branches on the driver.
5. **Schema is declared, not typed by hand.** `Schema::create()` takes a `TableDefinition` builder
   and emits correct SQL per driver: `INTEGER PRIMARY KEY AUTOINCREMENT` on SQLite,
   `BIGINT UNSIGNED AUTO_INCREMENT` + `ENGINE=InnoDB` + explicit charset/collation on MySQL. Default
   string length is **191** so that a `utf8mb4` index fits the older index limit of the assumed
   MySQL-family engine (provisional per `O-2`, to be confirmed) - a mistake that only appears on the
   host is the worst kind.
6. **Migrations are plain PHP files, numbered, returning `up`/`down` callables**, tracked in a
   `migrations` table with a batch number. Running them twice applies nothing the second time. A
   failing migration is named in the error and is **not** recorded as applied. No shell, no external
   tool: `php bin/migrate.php` (and the installer, which runs the same code) is the whole interface.
7. **The installer and the requirements check are separate from the CLI scripts that drive them**
   (`Installer`, `RequirementsChecker`), so a web installer for hosts without shell access can reuse
   them later without duplicating logic.
8. **Timestamps are UTC `DATETIME`**, written by the application clock (`Clock`), never by the
   database server: two engines and two hosts must produce comparable values.
9. **Installation problems are distinguishable from runtime failures.** `DatabaseException` carries a
   machine-readable code, and the setup-class codes (missing driver, failed connection, unwritable
   directory) map to HTTP **503** with an actionable Persian message; every other database failure
   stays a generic 500 whose detail exists only in the log.
10. **No secrets, paths or SQL text in any response.** The health endpoint reports
    `database: {driver, connected, migrations_pending}` and nothing else.

## Consequences

- The application owns its data layer, so every future convenience (relations, pagination helpers,
  soft deletes) is deliberate work rather than a library call. This is the cost of `C-4`.
- Supporting two engines means no engine-specific feature may be used without teaching
  `TableDefinition`/`Connection` about it (for example: partial indexes, `JSON` columns on MySQL,
  full-text search). Such needs must arrive with a documented fallback, not with a silent
  dependency on one engine.
- **MySQL does not support transactional DDL**, so a migration that fails halfway can leave a
  partially applied change; SQLite is transactional for DDL and is therefore the engine used to
  prove ordering and rollback behaviour locally. `bin/migrate.php` reports the failing migration by
  name and points to `--status` for recovery.
- Rollback (`down`) is best-effort by design: it exists for local development and for reverting a
  change made moments earlier, not as a backup strategy. Data safety is a separate concern (backups
  in a later phase).
- SQLite in local work is not a simulation of MySQL: type affinity, collation and locking differ.
  Anything that depends on those differences must be verified on the MySQL family before release -
  recorded as a risk until a host exists.
- The `pdo_mysql` driver is compiled into the development runtime (WASM PHP 8.4) but connecting
  through it **crashes the runtime** (a WASM trap rather than a catchable `PDOException`), so MySQL
  connectivity is the one path in this layer that cannot be executed until a real MySQL/MariaDB is
  available. Everything dialect-related that can be executed - rendered SQL, guards, migrations,
  installer behaviour on SQLite - is executed and asserted in the test suite.

## Alternatives considered

- **An ORM or query builder package** (Doctrine, Eloquent, Cycle, Aura): rejected - a runtime
  dependency needing Composer on the host, contradicts `C-4`/ADR-0002, and hides dialect behaviour
  that this project must control explicitly.
- **Raw SQL everywhere with a database-specific schema file**: rejected - it makes SQLite and MySQL
  diverge silently, which is exactly what happens when there is no host to test against.
- **Migration tool installed on the host** (Phinx, Laravel migrations, Doctrine migrations):
  rejected - requires shell/Composer access, which the target hosting does not provide.
- **Auto-synchronising schema from code annotations on every request**: rejected - an accidental
  drop/alter in production, and unacceptable on a shared host where requests must be cheap.
- **PostgreSQL or an embedded file database such as a MySQL-only design**: the engine choice belongs
  to the owner (`O-2`, provisional), so the layer keeps the engine behind configuration instead of
  committing the codebase to one.
