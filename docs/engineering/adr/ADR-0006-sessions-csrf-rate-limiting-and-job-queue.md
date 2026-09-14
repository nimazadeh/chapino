# ADR-0006: PHP-native sessions with a data-backed identity, deny-by-default CSRF, database-backed rate limiting, and a cron-driven job queue

- Status: accepted
- Date: 2026-09-14
- Deciders: engineering agent (implementation decisions inside already-ratified constraints; nothing reserved to the product owner was decided here)
- Rule reference: [security](../../../.agents/rules/security.md), [backend](../../../.agents/rules/backend.md), [database](../../../.agents/rules/database.md), [architecture](../../../.agents/rules/architecture.md)
- Related: [ADR-0002](ADR-0002-shared-hosting-target.md) (shared-hosting target), [ADR-0004](ADR-0004-iran-region-integrations.md) (SMS/OTP and payments are server-verified), [ADR-0005](ADR-0005-data-layer-and-migrations.md) (data layer); open decisions `O-6` (auth, decided: mobile + OTP), `O-2`/`O-3` (provisional), `O-20` (no real host yet)

## Context

Phase 0 slice 3 must make the application safe to put on a shared host before any product feature
exists: sessions and cookies, CSRF, abuse limits on writes, and a way to run background work. The
constraints that shape the decision:

- **commodity shared PHP hosting**: no shell, no long-running process, no Redis, no queue daemon, no
  Node at runtime (`C-4`, `C-9`, ADR-0002). Whatever background work exists must be triggered by
  something a shared host already provides;
- **one codebase, one database, no second technology**: the only storage available is the database and
  the filesystem (`O-2` provisional: MySQL family on the host, SQLite locally);
- **auth will be mobile + OTP** (`O-6`, decided), so a session is the only place a signed-in identity
  lives, and a leaked session identifier is a leaked account;
- **the owner has no real host yet** (`O-20`): everything must work under XAMPP, Laragon and `php -S`,
  where PHP settings differ (some hosts enable `session.auto_start`, some set their own save path);
- **abuse is a real cost on shared hosting** (DDoS/brute force protection is in the ratified
  constraints): unlimited anonymous writes can fill the database or exhaust a monthly request quota;
- maintenance work (purges, and later: SMS retries, payment reconciliation, print-house submission)
  must survive a request that dies halfway and must not run twice by accident.

## Decision

1. **PHP's native session storage, with an explicit policy, and the session file directory inside
   `storage/`.** `Session` configures `use_strict_mode`, `use_only_cookies`, `cookie_httponly`,
   `cookie_samesite=Lax`, `cookie_secure` when the request is HTTPS, and a garbage-collection lifetime
   derived from the absolute timeout. Settings are applied by the application, not inherited from an
   unknown `php.ini`, so the policy is the same on every host. If a session is already active (a host
   with `session.auto_start=1`), the application **adopts** it instead of trying to reconfigure it -
   PHP refuses those changes anyway, and a warning is logged.

2. **The database row is the identity; the cookie is only a pointer.** A row is written **only when
   somebody signs in**, and it stores `sha256(identifier)` - never the identifier itself - plus
   `user_id`, client IP, user agent, creation, last-seen and expiry. Signing in regenerates the
   identifier (session fixation), signing out deletes the row, and a session whose row is gone loses
   its privileges on the next request. Idle and absolute timeouts are enforced from the stored row.
   Anonymous visitors cost no row and no query: the middleware starts a session only when the request
   carries a session cookie or changes state, and the row lookup is skipped when the session holds no
   user. This keeps plain page views database-free and means an unmigrated database degrades only the
   features that genuinely need storage.

3. **CSRF is deny-by-default on every state-changing method.** The token is a 64-hex value stored in
   the session, accepted from a request header (`X-CSRF-Token`) or a body field (`_token`) - **never
   from the query string**, which leaks through logs, history and `Referer`. A present `Origin` header
   must match the request host (the check is skipped only when the header is absent, because
   command-line clients send none and carry no cross-site cookie either). The layer runs before any
   controller, so a route cannot forget it. `SameSite=Lax` is the second line, not the only one.

4. **Rate limiting is a fixed-window counter in the database**, keyed by `sha256(namespace + bucket +
   key)`, incremented with a conditional `UPDATE` and inserted on conflict so concurrent requests
   cannot lose a hit. The key is hashed so an IP is not stored in clear text; rows expire and are
   purged by the queue. The anonymous-write limit is configuration-driven and `0` disables the layer.
   Refusals are `429` with `Retry-After`, and the event is logged with the numbers.

5. **Background work is a database-backed queue driven by cron** (`bin/cron.php`), not a daemon. A job
   is a row: type, JSON payload, queue name, attempts, `available_at`, status
   (`queued`/`running`/`succeeded`/`failed`), reservation fields and last error. Claiming is an
   `UPDATE ... WHERE status = 'queued'` after a select, so two overlapping runs cannot both win a job.
   Failures are retried with exponential backoff (`min(3600, 30 * 2^(attempts-1))`) until
   `max_attempts`, then marked failed with a reason. A run is batch-bounded (`--limit`, `--seconds`),
   takes a lock file so two crons cannot fight, and records a stop reason
   (`batch_empty` / `batch_exhausted` / `job_limit_reached` / `time_limit_reached`).

6. **Handlers are registered explicitly and must be idempotent.** `app/Jobs/handlers.php` maps a type
   name to a closure; an unknown type fails loudly rather than being interpreted, so a queued payload
   can never choose which code runs. Every handler states in one line why running it twice is harmless.

7. **No database table grows without a limit.** `bin/queue.php` exists for the operator: counts,
   recent jobs, one job in full, `--push` to enqueue, and `--retry` to put a failed job back.

## Alternatives considered and rejected

- **A JWT or a self-contained signed cookie instead of a server-side session**: rejected because
  revocation is a requirement (an OTP-authenticated account must be able to end a session immediately,
  and support/abuse handling needs it) and a signed cookie cannot be revoked without a store.
- **The session file as the identity (`$_SESSION['user_id']` alone)**: rejected - the client-side
  identifier would then be the whole credential, with no server-side record to expire, revoke or audit.
- **In-process or APCu rate limiting**: rejected - APCu is not guaranteed on shared hosting (absent in
  the development runtime too), and a limit that resets on every request or worker is not a limit.
- **A file-based queue**: rejected - no atomic claim across processes without locking gymnastics, no
  queryable history, no retry bookkeeping, and it duplicates what the database already provides.
- **A long-running worker / Redis / cron-per-job**: rejected by the ratified shared-hosting constraint.
  Cron-per-job would also put thousands of scheduled entries on a host that limits them.
- **Trusting `session.auto_start` and the host's `php.ini`**: rejected - it makes security behaviour a
  property of the host, and it breaks the "same code, every host" requirement.

## Consequences

- The database is now on the path of **state-changing** requests (rate limiting) and of authenticated
  requests (identity), so a database outage degrades those, not plain browsing. The failure is
  reported as a setup problem when it means "not installed yet" (`database_schema_missing` →
  `503 setup_required` with the exact command), and as a generic error otherwise.
- Sessions require a writable directory: the installer creates `storage/sessions` with `0700`, and a
  missing directory is created on demand with the same mode.
- Queue latency equals the cron interval; the owner must configure the host's cron. This is documented
  as an installation step, not hidden.
- There is **no per-job timeout** in Phase 0: a handler that hangs holds its batch until PHP's own
  `max_execution_time`. Handlers must be short and bounded; long work is split into more jobs. Recorded
  as a remaining risk and revisited when the first real handler (SMS, payment reconciliation) exists.
- MySQL-family behaviour of the queue and rate limiter is **code-verified, not executed**: the
  development runtime cannot open a MySQL connection (it aborts the wasm runtime rather than throwing),
  and no host exists yet. Everything is executed against SQLite, and the dialect-specific parts are
  isolated in `Connection`, `TableDefinition` and the migration files.
- Nothing reserved to the product owner was decided here: no technology was added, no provider chosen,
  and `O-13` (print house) and `O-10` (B2B shape) remain open.

## Follow-up

- Re-verify the queue and rate limiter against the real engine during the installation runbook
  (phase 0 item 0.10) once a host exists (`O-20`).
- Add a per-job timeout and a dead-letter view when the first integration handler is written.
- Revisit the anonymous-write limit against real traffic after launch; the value ships as configuration.
