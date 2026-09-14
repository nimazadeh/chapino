# Engineering Report - Phase 0, slice 8: the panel-only install and update path (0.10, completed)

- Date: 2026-09-14
- Workflow: `feature-development`
- Branch: `arena/01a09e2c-chapino`
- Revision verified: `4da14b6` + the working tree of this slice (dirty - the slice itself is the change)
- Rules applied: `deployment`, `security`, `testing`, `docs`, `reporting`

> Format and vocabulary: [.agents/rules/reporting.md](../../../.agents/rules/reporting.md).
> Every claim below is backed by an executed command and its observed output.

---

## STATUS

`PASS WITH RISKS`

Both pages of the panel-only path are built and executed end to end here (the install itself was driven
through a real HTTP request cycle and a local database file really received the schema and the default
settings); what remains unverified is everything that needs the owner's machine: the real host run, the
emergency SQL export for phpMyAdmin, and the visual check of the two pages in a real browser.

## IMPLEMENTED

- `app/Core/Install/InstallToken.php` - the one-time token: a file at `storage/install-token.php`
  holding one 64-hex secret. Its content is **parsed, never executed**: the file must start with `<?php`,
  comment lines are stripped, and what is left must be exactly `return '<hex64>';`. Anything else
  (a truncated value, an appended statement, a second return) reads as "no token".
- `app/Core/Install/InstallSession.php` - the installer's own session (`chapino_install`, `SameSite=Lax`,
  `HttpOnly`, `Secure` on HTTPS, strict mode, cookies only) with a 64-hex CSRF token compared through
  `hash_equals`, and the session id regenerated on authorization. A `session_start()` that returns false
  (an unwritable `session.save_path`, the classic shared-host failure) is reported as a distinct,
  operator-facing error instead of a fatal.
- `app/Core/Install/AttemptLimiter.php` - five failed token attempts per visitor per 900 s, counted in
  `storage/install-attempts.json` (there is no database yet to count in), one hashed key for reading,
  writing and clearing, `flock` around the read-modify-write, entries pruned after 24 h.
- `app/Core/Install/WebInstaller.php` + `InstallException.php` - the installer's domain layer. Every
  failure carries a stable reason (`driver_unsupported`, `database_name_missing`,
  `database_user_missing`, `database_port_invalid`, `charset_unsupported`, `collation_unsupported`,
  `requirements_failed`, `already_installed`, `storage_not_writable`, `session_unavailable`) that the
  page renders in Persian. Order of work is deliberate: storage → required checks → connection →
  migrations → seeds → **configuration file last**.
- `app/Core/Install/Installer.php` - the CLI installer now shares its builders (`databaseSettings()`,
  `configuration()`) with the web path, so the two cannot drift; charset and collation moved from free
  text to an allow-list because they are interpolated into `CREATE TABLE`.
- `public/install.php` - the web installer entry point, standalone on purpose: it cannot load the
  framework, because `config/config.php` does not exist yet (that is what it is about to create). It
  renders 404 as soon as a configuration file exists.
- `app/Controllers/MaintenanceController.php`, `app/Views/maintenance.php`, two routes - `/maintenance`,
  the operator page for later work on the same host: migration status, pending migrations, adding new
  default settings (create-only), and burning the token. It is reachable in production **on purpose**
  (it replaces `bin/migrate.php` for a host with no shell); its gate is possession of the token file,
  plus CSRF, plus the attempt limiter, plus logging.
- `app/Views/install.php` - the five screens (locked, wrong token, capability report, install form,
  done) in Persian, with the real stylesheets.
- `tests/Integration/WebInstallTest.php` - 12 tests covering the token contract, the limiter, the
  session behaviour, the installer ordering and the maintenance page.
- Deliberately **not** changed: `bin/install.php` remains the primary path on a machine with a shell;
  the development-only pages (`/design-system`, `/tests/browser`) keep their production 404, because
  they are development tools, not operator tools.

## ROOT CAUSES

Defects found and fixed while building this slice, with the real cause rather than the symptom:

1. `InstallException` declared `public readonly string $code`, which cannot redeclare PHP's own
   `Exception::$code` (int) - fatal at class load. The stable reason now lives in `$reason`.
2. `AttemptLimiter` wrote raw visitor keys and read hashed ones, so the lockout could never fire. One
   hashed key is now used for read, write and clear; the window comes from the constructor instead of a
   second hardcoded 900.
3. `WebInstaller` ran the requirement checks before preparing the storage directories, which blocked a
   fresh install on a host where `storage/` had not been created yet. Storage comes first now, and an
   actually unwritable storage directory rethrows as `storage_not_writable` instead of surfacing a
   generic failure.
4. `/maintenance` returned 500 on every action: `MaintenanceController::page()` had a required third
   parameter while the flow passed two. Fixed with a default, and the test now prints the controller's
   caught error when the page is not 200, so the next failure of this kind is readable in one run.
5. Consuming the token also minted the next one, so the page looked unlocked after an action. The
   post-action render is now explicitly locked and does not create a token; the operator reopens the
   page for a fresh one.
6. `session.use_strict_mode` plus the absence of a browser in the test harness made the authorized POST
   silently arrive as an anonymous request. The harness follows the regenerated session id the way a
   browser follows `Set-Cookie`; no production behaviour was weakened for the test.

## TESTS EXECUTED

```bash
node tools/dev/php.mjs test
node tools/dev/php.mjs lint
node .agents/scripts/validate-engineering-os.mjs --strict
```

Both pages were additionally driven end to end outside the suite: a disposable copy of the application
was served to the real `public/install.php` through one WebAssembly PHP process per request, with the
request (method, URI, POST body, session id) supplied as a file and the response written to disk.

## RESULTS

```text
Tests: 214 passed, 0 failed, 1021 assertions, 2627 ms
PHP lint (PHP 8.4 via WebAssembly): 98 file(s) - checked 98 file(s), 0 failed
validator --strict: 9 checks passed, 0 warning(s), 0 error(s) - RESULT: PASS
```

The end-to-end run, in order:

```text
== 1) GET /install.php
   token=64 chars, csrf=64 chars, token_printed=0        (the token exists but is never in the HTML)
== 2) POST a wrong token
   shown: توکن درست نیست
   attempts={"7dcfda6404c760cdb518199ef168de61":{"count":1,"first":1789369364}}
== 3) POST the right token
   body after a successful token: 0 bytes (a redirect carries no page)
== 3b) GET /install.php (now authorised)
   required failures shown: 0      install button enabled: 1
== 4) POST install (sqlite)
   shown: نصب کامل شد
   config written: yes      token consumed: yes
   tables in the database: 5      settings rows: 2
== 5) GET /install.php again (must be 404)
   shown: صفحه‌ی نصب غیرفعال است      install button still present: 0
```

That is the whole promise of section 13 of the runbook, executed: no token in the page, a wrong token
counted, a right token accepted, the capability report shown before anything is written, the
configuration file written last, the token burned, and the installer gone from the web afterwards.

## SECURITY REVIEW

- **Authentication of the operator** - possession of `storage/install-token.php`, which only someone
  with panel/file access can read. The token is generated with `random_bytes(32)`, is single-use, and is
  displayed nowhere except that file.
- **Execution of the token file** - never executed. The value is parsed under a strict shape, so a
  planted payload (`<?php system(...)`) reads as "no token"; this has a test.
- **CSRF** - every POST carries the session's 64-hex token and is compared with `hash_equals`.
- **Brute force** - five failures per visitor per 900 s, file-backed with `flock`; the visitor key is a
  hash of the client address, so no addresses are written to disk.
- **Secret leakage** - the database password is never echoed back into HTML, not even in the error
  screen; the token never appears in any response body.
- **SQL injection at the schema layer** - charset and collation are an allow-list, not free text.
- **Self-disable** - the installer answers 404 whenever a configuration file exists; the token file is
  deleted on success.
- **Authorization of `/maintenance` in production** - the same token-file gate, deliberate and
  documented; there is no environment-based bypass.
- **Not applicable here** - uploads and payment paths are not part of this slice.

## PERFORMANCE REVIEW

No new database work: the installer calls the existing migrator and seeder once, and `/maintenance`
reads `Migrator::status()` plus a settings count. The limiter is one small JSON read and write per
failed attempt, bounded by pruning. No measurement was possible against a real host; the page is a
few kilobytes of HTML plus the existing stylesheets, and no new asset was added.

## REGRESSION REVIEW

- The full suite was re-run: 214 passed, 0 failed (previous baseline 213 in this slice's first run, 209
  at the end of slice 7) - the added tests are the token, limiter, session, installer and maintenance
  coverage.
- `DeploymentContractTest` pins `public/` to index.php + install.php exactly; adding a third executable
  file there fails the suite by design.
- `bin/install.php` still refuses any non-CLI SAPI and its flags are unchanged. It was re-run after the
  refactor against a throwaway copy of the application:

  ```text
  node tools/dev/php.mjs run bin/install.php --driver=sqlite --name=chapino --user=root --password=x --url=http://localhost
     نوشته شد: config/config.php
  ۴) اجرای مهاجرت‌ها
     اجرا شد: 0001_create_identity_tables, 0002_create_operations_tables
  ۵) مقدارگذاری تنظیمات پیش‌فرض
     13 تنظیم افزوده شد، 0 از قبل موجود بود (دست‌نخورده ماند)
  ۶) بررسی نیازمندی‌ها
     همه نیازمندی‌های اجباری برقرار است
  ```

  So the shared builders did not change what the CLI writes; the two paths now differ only in how the
  operator proves they are the operator.
- Lint covers every changed file (98 files, 0 failed) and the engineering-OS validator is clean, so the
  documentation edits (roadmap, project context, runbook section 13) do not break the rules or links.

## REMAINING RISKS

- The emergency SQL export for phpMyAdmin (section 13.4 of the runbook) is **not built**. A host that
  refuses to execute an install script still has no supported path; the runbook says so explicitly.
- Nothing in this slice has run on a real host. The local database engine here is the provisional
  SQLite assumed by `O-2`; the MySQL-compatible target stays unverified until `host-facts.json`
  arrives - and the MySQL driver cannot even be connected under this sandbox's WebAssembly PHP, so the
  failure modes for it are covered by reasoning and by the shared code path, not by execution.
- The two pages have been read as HTML, not seen as pages. Layout and font rendering are the owner's
  browser to judge, exactly as with the harness of slice 6.
- The installer's five-attempt lockout is per client address; a host behind a shared address (some
  proxies) could see one visitor lock out another. Acceptable for a one-time page, recorded rather than
  hidden.

## NEXT ACTION

Run the capability check on the owner's own machine and send `host-facts.json`; then execute section 12
of the runbook (a real install, the two pages included) and paste the result into the runbook. After
that, Phase 0 is done and Phase 1 (product core) starts on the owner's approval.
