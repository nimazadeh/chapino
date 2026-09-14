# Engineering Report - Phase 0, slice 7: the install and deployment runbook (0.10, started)

- Date: 2026-09-14
- Workflow: `feature-development`
- Branch: `arena/01a09e2c-chapino`
- Revision verified: `6173a51` + the working tree of this slice
- Rules applied: `deployment`, `security`, `docs`, `reporting`, `testing`

> Format and vocabulary: [.agents/rules/reporting.md](../../../.agents/rules/reporting.md).
> Every claim below is backed by an executed command and its observed output.

---

## STATUS

`PASS WITH RISKS`

The runbook exists and every claim in it that can be checked without the owner's machine is pinned by
tests; the host-side steps themselves are unverified until someone runs them on XAMPP, Laragon or
`php -S`, and item 0.10 still owes the web installer for hosts that have no shell.

## IMPLEMENTED

- `docs/engineering/runbooks/install-and-deploy.md` - the runbook: prerequisites and the capability
  check, the document-root rule, XAMPP / Laragon / `php -S` in full, the URL/rewriting requirement,
  database setup (the target engine, plus the provisional local SQLite assumption), configuration
  keys, the six install steps,
  post-install verification, cron, production mode, backup and upgrade, a troubleshooting table, an
  explicit "what is verified where" table, the paste-in block for `host-facts.json` (`O-20`), and the
  design of the remaining work (panel-only installer).
- `.htaccess` (repository root) - fail closed when the document root is one level too high, which is
  the most common local-host mistake and would otherwise publish `config/` and `storage/`.
- `storage/.htaccess` + `.gitignore` - runtime state (sessions, logs, uploads, and the database file
  in the provisional local SQLite setup) is denied even when the host decides where the document
  root is. The deny file is committed on purpose;
  runtime state still is not.
- `tools/dev/serve-router.php` - `php -S` has no rewriting: this is the two lines that make the local
  command serve the real routes, and it refuses to run under any SAPI but the built-in server.
- `tests/Unit/DeploymentContractTest.php` - six tests pinning the deployment contract: the root deny
  file, `storage/` self-defence and `.gitkeep`, "nothing but the front controller executes in the web
  root", no database/log/`.env` file inside `public/`, no credentials in the configuration template,
  and the router's development-only guard.
- Corrected statements that were not true: `public/index.php`, `public/.htaccess` and ADR-0002 all
  claimed that *generated links* fall back to `/index.php?r=/path`. Only the input side supports that
  form; generated links are clean URLs and therefore need rewriting. The three texts now say this, and
  the runbook records the consequence for a host without rewriting.
- `docs/engineering/README.md` (the stale "there is no product code yet" banner, and a link to the
  runbook), `project-context.md` (new "install and deployment" row) and `roadmap.md` (0.10 state).
- Deliberately not changed: the installer class and the CLI scripts. The runbook documents what exists
  rather than introducing a second installation path before the first one is exercised on a real host.

## ROOT CAUSES

Writing the runbook is what surfaced these; none of them came from running a test that already existed.

1. **A documented capability that was never implemented.** `index.php` and `.htaccess` both stated that
   links are generated as `/index.php?r=/path` when rewriting is unavailable. The `?r=` form is
   accepted on input (`Request::resolvePath`), but every link the application generates is clean. On a
   host without rewriting, navigation would break while the documentation promised it works. Escaped
   detection because no test asserted anything about the *output* side of that claim - the router tests
   only ever fed the fallback in. Now stated correctly in all three places, with the gap recorded as a
   known limitation tied to the host report.
2. **Fail-open on a wrong document root.** Nothing stopped a server pointed at the repository root from
   listing `app/`, `config/` and `storage/`. The documented rule existed only as prose. Now a deny file
   fails closed, and a test pins it.
3. **`php -S` could not serve the application.** The owner ratified "must run under XAMPP, Laragon and
   `php -S`", but the built-in server has no rewriting, so every route except `/` returned 404. The
   missing piece was a router script, not application code.
4. **Storage had no self-defence.** `storage/` holds sessions and (locally) the database itself; a host
   that dictates the document root had nothing stopping a download. Fixed, and kept out of the ignore
   rule's way.

## TESTS EXECUTED

```bash
node tools/dev/php.mjs lint
node tools/dev/php.mjs test
node tools/dev/php.mjs test --filter=DeploymentContract
node tools/dev/browser-tests.mjs
node .agents/scripts/validate-engineering-os.mjs --strict
```

## RESULTS

- `node tools/dev/php.mjs test` → `Tests: 202 passed, 0 failed, 956 assertions, 2587 ms`.
- `node tools/dev/php.mjs test --filter=DeploymentContract` → `Tests: 6 passed, 0 failed, 25 assertions`.
- `node tools/dev/php.mjs lint` → `checked 88 file(s), 0 failed` / `PHP lint passed.`
- `node tools/dev/browser-tests.mjs` → `Tests: 14 passed, 0 failed, 14 total (jsdom)`.
- Validator `--strict` → `9 checks passed, 0 warning(s), 0 error(s)` / `RESULT: PASS`.
- Writing the runbook tripped the validator's `premature-stack` check 17 times before the qualifiers
  were added (`mentions reserved technology "mysql" as if decided`): every host-side technology the
  runbook names now says that it is an assumption (`O-2`, `O-3`, `O-20`), which is exactly the
  behaviour that check exists to force.
- The one new assertion that failed first time was mine, not the code's: it demanded the literal
  `'/public'` in the router while the router resolves `'/../public'` relative to itself. Corrected to
  assert the real contract rather than my memory of it.

## SECURITY REVIEW

- **Web root**: `public/` only; a wrong document root now fails closed (root `.htaccess`), and a test
  walks `public/` to prove no database, log or `.env` file lives there.
- **PHP execution in the web root**: `public/.htaccess` denies every `.php`/`.phtml`/`.phar` and grants
  only `index.php`; the new test pins both halves, because granting the front controller without
  denying the rest is the failure that matters.
- **Runtime state**: `storage/` denies access; the deny file is committed while the state is not.
- **Secrets**: the configuration template is asserted to carry an empty SMS key, merchant id and
  database password, and to ship with `env=production` and `debug=false`. A template that ships a real
  key is the classic leak, and the test runs on every commit.
- **The router**: refuses to execute under any SAPI except `cli-server`, resolves files under
  `public/` only (the candidate must be a real file inside the web root), and is not referenced by the
  application. It is not deployed.
- **No new runtime surface**: the runbook adds documentation and server configuration only; no route,
  controller or query was added, so the request pipeline is unchanged.

## PERFORMANCE REVIEW

- No product code path changed; the additions are documentation, two deny files and a development
  router. The suite's total runtime moved from 2246 ms to 2587 ms with six new tests (the difference is
  filesystem walks of `public/` in the new contract test).
- The router serves static files by returning `false`, so the built-in server keeps its own (faster)
  static path instead of routing bytes through PHP.
- No measurement of a real host is possible here: no PHP binary and no web server exist in this
  environment. The runbook states this in its own "what is verified where" table.

## REGRESSION REVIEW

- Full suite re-run after every edit: `202 passed, 0 failed` (up from 196/0 in slice 6); lint
  `88 files, 0 failed`; browser cases `14 passed`; validator `9 checks, 0 warnings`.
- The `.htaccess` and `.gitignore` edits cannot affect the test process (they are server and VCS
  configuration), and the suite was green both before and after.
- The comment corrections in `public/index.php`, `public/.htaccess` and ADR-0002 change no behaviour:
  the front controller's output is asserted by the existing page tests, which still pass.
- `storage/.gitkeep` is asserted to survive, so cloning and installing still behaves as before.

## REMAINING RISKS

- **The host-side steps have never been executed by this project.** XAMPP, Laragon and `php -S` exist on
  the owner's machine, not here: the runbook says so explicitly and asks for the capability report.
  Until that arrives, "installs on XAMPP" is a documented expectation, not a verified fact (`O-20`).
- **Item 0.10 is not complete.** The remaining half is the panel-only installation (web installer,
  web migration runner, SQL export as a fallback) for shared hosts without shell access. Section 13 of
  the runbook contains the design; it needs the owner's go-ahead because it adds a temporary web
  surface, which is a security-sensitive decision.
- **A host without URL rewriting still breaks navigation**, because generated links are clean URLs. The
  fix (a URL mode that emits `index.php?r=`) is small but should be written against a real host's
  behaviour rather than guessed, so it waits for `O-20`.
- `.htaccess` is Apache-only. On nginx the document-root rule and the storage rule must be configured
  in the server block; the runbook says this in words, since a file cannot enforce it there.
- The local engine of the target database is still an assumption (`O-2`): MySQL/MariaDB compatibility is
  asserted by the data layer's design, not by a connection to a real MySQL server, which cannot be
  opened from this environment.

## NEXT ACTION

Owner: run `php bin/check-requirements.php --json > host-facts.json` on XAMPP (or Laragon) and paste
the result into section 12 of the runbook, then say whether to build the panel-only web installer.
