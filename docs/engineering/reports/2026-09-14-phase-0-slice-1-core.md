# Engineering Report - Phase 0, Slice 1: Application Core

- Date: 2026-09-14
- Workflow: [feature-development](../../../.agents/skills/feature-development/SKILL.md) (the phase's
  first slice; Phase 0 of [the roadmap](../../product/roadmap.md))
- Branch: `arena/01a09e2c-chapino`
- Revision verified: the staged tree of the commit `feat(core): application core, Persian validation
  and installation self-check`. The commit hash is reported in the task response: a file cannot
  contain the hash of the commit that introduces it.
- Rules applied: `architecture`, `backend`, `frontend` (HTML landing), `qa`, `debugging`,
  `security`, `localization`, `code-review`, `git-workflow`, `reporting`

---

## STATUS

`PASS WITH RISKS`

The application core was implemented and **executed**: 48 tests pass with 131 assertions, every PHP
file passes a parse check whose failure mode was itself verified against a deliberately broken file,
and the real front controller serves correct responses (200 / 200 / 404) with a configured
installation and an honest 503 with install instructions when configuration is missing.

Residual risk is environmental and named below: the host is not yet known, the local runtime is PHP
8.4 (the host may be older), and the database layer does not exist yet - so Phase 0 as a whole is
**not** complete.

## IMPLEMENTED

**Runtime core** (`app/`, `public/`, `config/`, `bin/`, `storage/`)

| File | Responsibility |
| --- | --- |
| `public/index.php` | Front controller: the only PHP file the web server must reach |
| `public/.htaccess` | Rewrite to the front controller **and** a working no-rewrite mode; denies execution of any other PHP file in the web root; disables directory listings |
| `app/bootstrap.php` | Constants, autoloader registration, clock; idempotent so CLI scripts and tests can include it repeatedly |
| `app/Core/Autoloader.php` | Hand-written PSR-4 loader (no Composer, by design) |
| `app/Core/Application.php` | Container and request lifecycle: middleware pipeline -> router -> controller -> response, plus a fallback error path that works when configuration itself is broken |
| `app/Core/Config.php` | Typed, dot-notation configuration with safe defaults and an explicit path override (`CHAPINO_CONFIG`) |
| `app/Core/Request.php` | Untrusted input access; resolves the path for rewrite, PATH_INFO and `?r=` modes; refuses to trust unparseable client addresses |
| `app/Core/Response.php` | JSON success/error contract, HTML, redirect; security headers |
| `app/Core/Router.php` | Explicit route table with `{param}` segments; distinguishes 404 from 405 |
| `app/Core/ErrorHandler.php` | One place where a failure becomes a safe Persian response with a request id, while the detail goes to the log |
| `app/Core/Logger.php` | Append-only JSON-lines logging with a lock, level filtering and mandatory redaction |
| `app/Core/Redactor.php` | Strips secrets and personal data (password, token, mobile, email, national id, ip, otp …) from every log context |
| `app/Core/Clock.php` | Single source of "now", freezable in tests; UTC storage |
| `app/Core/HttpException.php` | Errors that are safe to show, with the vocabulary of the product (404/401/403/405/422/429/500) |
| `app/Core/Validation/Validator.php` | Rule-based server-side validation: presence, type, bounds, enum, email, URL, Iranian mobile/postal-code/national-ID, with Persian messages and all errors returned at once |
| `app/Core/Validation/PersianText.php` | Persian text normalization (Arabic yeh/kaf, diacritics, tatweel, spacing, ZWNJ preserved), digit conversion both ways, Persian number formatting (۱٬۲۵۰٬۰۰۰), Iranian mobile canonicalization, national-ID checksum |
| `app/routes.php`, `app/middleware.php` | The route table and the middleware pipeline as explicit, readable lists |
| `app/Controllers/HealthController.php` | `GET /api/health`: status, environment, request id, and capabilities - including `ai_generation: false` from `C-12` |
| `config/config.example.php` | Committed template; the real `config/config.php` is git-ignored and holds secrets |
| `bin/smoke.php` | Installation self-check: runs the real front controller in a fresh process, then dispatches further paths, reporting status and a Persian hint when the installation is unconfigured |
| `tests/` | Dependency-free test runner, `TestCase`, and 6 test classes (unit + integration) |
| `tools/dev/` | Development-only PHP runner (WebAssembly) plus `lint-files.php`; explicitly not part of the product |

Deliberately **not** created: database layer, migrations, installer, sessions, CSRF, rate limiter,
job queue, cron entry point, design system CSS, fonts, and any product feature. They are slices 0.2
to 0.4, and mixing them into this slice would have made the diff unreviewable.

## ROOT CAUSES

Not a bug-fix task, but execution found **nine real defects** that reading the code had not revealed.
This is precisely why the operating system forbids claiming success without running anything.

| # | Defect | Root cause | How it was found |
| --- | --- | --- | --- |
| 1 | The lint tool reported "All PHP files parse cleanly" for a file with a syntax error - a check that could not fail | `php.runStream({code, argv})` runs the code, not the CLI: `argv` was ignored, so `<?php` was executed and the exit code was always 0 | Negative control: a deliberately broken file was linted and passed |
| 2 | `Undefined constant APP_ROOT` in every integration test | The test runner never defined what `bootstrap.php` normally defines | First suite run |
| 3 | A national-ID test asserted a valid value that the algorithm rejects | The test carried a value from memory instead of computing the check digit (sum 122, 122 % 11 = 1) | First suite run; the expected value was then recomputed by hand |
| 4 | `?r=`-style and PATH_INFO URLs kept the front-controller prefix in the route path | `resolvePath()` only stripped the script name when the URI *ended* with it | Request unit test |
| 5 | Persian digits were not accepted by `integer`-typed fields | Rules ran before normalization, so `۳` never became `3` | Validator unit test |
| 6 | Every request failed with `Using $this when not in object context` | The middleware pipeline built `static fn` closures that referenced `$this` | Integration test |
| 7 | Including the front controller inside the test process fatally redeclared the autoloader class | `bootstrap.php` used `require` where `require_once` was needed, and the loader registered itself twice | Front-controller smoke test |
| 8 | Missing configuration produced a fatal error instead of a response | The error handler is built from configuration, and it was resolved **outside** the try block, so the very failure it existed to handle escaped it | `bin/smoke.php` on an unconfigured installation |
| 9 | In-process status assertions were meaningless: an unknown route reported 200 | `http_response_code()` cannot take effect once output has started (the test runner had already printed), so the SAPI value silently stayed 200 | Analysing the smoke result; the check was redesigned to read the status from the `Response` value and to exercise the real front controller first |

Two further problems were my own test bugs (an assertion using array syntax on a `Response` object,
and a status assertion left in a test where status cannot be observed); both were corrected in the
tests rather than by weakening the product.

## TESTS EXECUTED

```bash
# Development runner (no native PHP in this sandbox); see tools/dev/README.md
node tools/dev/php.mjs lint
node tools/dev/php.mjs test
node tools/dev/php.mjs run bin/smoke.php                                  # unconfigured: must fail clearly
node tools/dev/php.mjs run bin/smoke.php --config=/tmp/cfg/config.php     # configured: must pass

# Negative control for the linter itself
printf '<?php\nbroken_syntax( {\n' > app/Core/Broken.php
node tools/dev/php.mjs lint app/Core/Broken.php
rm app/Core/Broken.php

# Governance layer
node .agents/scripts/validate-engineering-os.mjs --strict
```

## RESULTS

- `node tools/dev/php.mjs lint` -> `PHP lint (PHP 8.4 via WebAssembly): 31 file(s)` /
  `checked 31 file(s), 0 failed` / `PHP lint passed.`
- Negative control: the same command on a deliberately broken file printed
  `PARSE ERROR /home/user/chapino/app/Core/Broken.php  syntax error, unexpected token "{" (line 2)`
  and exited non-zero. The linter can fail, therefore its pass means something.
- `node tools/dev/php.mjs test` -> `Tests: 48 passed, 0 failed, 131 assertions, 244 ms`, exit 0.
  Coverage by class: `PersianTextTest` (12 tests: normalization idempotence, digits, ZWNJ
  preservation, mobile in six input forms plus five rejects, postal code, national-ID checksum),
  `ValidatorTest` (12: happy path, missing required, invalid mobile, optional absent, optional
  present-but-invalid, string bounds at the exact boundary, integer coercion from Persian digits,
  enum membership, all-errors-at-once, unknown rule raises instead of passing silently),
  `RouterTest` (7: static, root, path parameter, 404, 405 with the allowed method list, handler
  contract violation, no cross-segment matching), `RequestTest` (6: input precedence, `only()`,
  state-changing methods, no-rewrite mode, PATH_INFO mode, invalid client address fallback,
  request-id uniqueness), `ConfigurationFailureTest` (2: missing and invalid configuration both
  produce 503 `config_error` with no filesystem path or stack trace leaked),
  `FrontControllerSmokeTest` (4: health payload through the real front controller, no-rewrite mode,
  Persian RTL landing page, 404 body shape).
- `node tools/dev/php.mjs run bin/smoke.php` (no configuration) -> three FAILs with
  `status=503 (expected 200/200/404)`, `config_error`, and the Persian installation hint: an
  unconfigured installation is reported honestly instead of appearing to work.
- `node tools/dev/php.mjs run bin/smoke.php --config=/tmp/cfg/config.php` -> `0 check(s) failed`:
  `front controller (public/index.php) / status=200`, `/api/health status=200`,
  `/api/no-such-route status=404`, exit 0.
- `node .agents/scripts/validate-engineering-os.mjs --strict` -> `9 checks passed, 0 warning(s),
  0 error(s)` / `RESULT: PASS` (structure, skills, rules, registry sync, links and anchors,
  contradictions, premature-stack, project-context, report-template).
- Not executed, therefore unverified: anything requiring the real host (PHP version, MySQL/MariaDB,
  cron, outgoing HTTP), anything requiring a browser (the design studio, RTL rendering with real
  fonts, canvas), and all database behaviour (the layer does not exist yet).

## SECURITY REVIEW

- **Server-side authority**: every value that matters is resolved server-side; the health endpoint
  computes capability instead of accepting it, and the middleware rejects oversized bodies before
  anything reads them.
- **Error disclosure**: a 500 returns a generic Persian message plus a request id and **no** class
  name, path, stack trace or internal value (asserted by test, including the debug-off case);
  configuration failures return the authored install instruction and no filesystem path.
- **Logging and personal data**: `Redactor` masks passwords, tokens, session/CSRF values, OTP codes,
  national ids, mobile numbers, emails, addresses and IPs; a test asserts that none of those values
  reach the log file while non-sensitive context does.
- **Input handling**: all input is read through `Request`, which never trusts a client-supplied
  forwarded address or an unparseable IP; validation is server-side and authoritative; unknown rules
  raise instead of silently passing.
- **Web-root hardening**: `.htaccess` denies execution of any PHP file other than the front
  controller, disables directory listing, sets `nosniff`; responses add `X-Frame-Options`,
  `Referrer-Policy`, `X-Request-Id`, and HSTS only when the request arrived over HTTPS. Session
  cookie policy, CSRF protection and rate limiting are **not** implemented yet - they are slice 0.3,
  and their absence is the main security gap of this slice.
- **Secrets**: `config/config.php`, `storage/*` and development `node_modules` are git-ignored; the
  example configuration contains only empty values; no secret exists in the repository.

## PERFORMANCE REVIEW

- Structural work only; no measurement was possible (no server, no browser, no database). What was
  built deliberately: dependencies are resolved once per request instead of per middleware layer;
  logging is a single append with `LOCK_EX`; the health endpoint does no I/O.
- No performance claim is made. Numbers require the host and real data (`O-10`, `O-12`).

## REGRESSION REVIEW

- Existing behaviour that could be affected: the governance layer only. `README.md` and
  `.gitignore` were updated; nothing from the previous commits was removed or rewritten.
- Checks re-run after every change: the full test suite (48 tests), the full parse check (31 files)
  and the engineering-OS validator in strict mode. All three are green on the final revision.
- `git status` was clean before the work started, and no other branch was touched.

## REMAINING RISKS

1. **The host is still unknown (`O-20`).** PHP version, database engine, cron availability, upload
   limits and outgoing HTTP are assumptions. The local runtime is PHP 8.4 while a shared host may run
   8.1 or older; the code avoids newer syntax, but this is unverified against the real target.
2. **No database layer yet**, so nothing about persistence, transactions, migrations or Persian
   collation is proven. The next slice must decide nothing here without the host facts.
3. **No session, CSRF or rate limiting yet.** Until slice 0.3, the application must not be exposed
   with any state-changing endpoint. This is the most important open item in the current code.
4. **Status codes are only asserted in a fresh process.** Inside the test runner the SAPI cannot set
   headers, so `tests/` asserts payload contracts while `bin/smoke.php` asserts statuses. The gap is
   documented in the test class; real HTTP assertions arrive with browser/HTTP testing on the host.
5. **No browser or HTTP automation exists**, so RTL rendering, Persian fonts and client behaviour are
   entirely unverified (they are Phase 1 work).
6. **`intl` is unavailable** in the development runtime and may be missing on the host, so all
   Persian handling is hand-written and must stay that way unless `O-20` proves `intl` exists.
7. **Jalali date conversion is not implemented yet** - deliberately deferred to the phase that
   displays dates (Phase 3), where it will arrive with a reviewed converter and tests.

## NEXT ACTION

Slice 0.2: the database layer (written against the MySQL-family engines that Iranian shared hosts
commonly provide - an assumption to be confirmed from the host panel, `O-2`/`O-20` - plus SQLite so
the layer can be tested locally), the migration runner and the schema foundation - plus the installer that writes configuration and runs
migrations without shell access. It starts immediately, and `bin/smoke.php` will not report a healthy
installation until that slice lands.
