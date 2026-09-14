# Engineering Report - Phase 0, slice 6: the browser test harness

- Date: 2026-09-14
- Workflow: `feature-development`
- Branch: `arena/01a09e2c-chapino`
- Revision verified: `27318a6` + the working tree of this slice
- Rules applied: `reporting`, `testing`, `code-quality`, `frontend`, `security`, `deployment`

> Format and vocabulary: [.agents/rules/reporting.md](../../../.agents/rules/reporting.md).
> Every claim below must be backed by an executed command and its observed output.

---

## STATUS

`PASS WITH RISKS`

The cases run and pass in the runner available here (jsdom, 14 of 14), and the development-only page
that executes the same cases in a real browser is delivered with the guard tests that keep it out of
production - but no real browser exists in this environment, so the one thing only a browser can
answer (layout, fonts, RTL shaping) remains unverified and is reported as such.

## IMPLEMENTED

- `tests/browser/fixtures.html` - one `<template id="fixtures">` holding 7 number fixtures and 4 form
  fixtures. Cloned per case, removed afterwards: a failing case cannot pollute the next one.
- `tests/browser/cases.js` - 14 cases covering the documented behaviour of the base script: Persian and
  Arabic-Indic digits, grouping, the minus sign, fractional and invalid values, idempotence, CSRF
  injection into `POST` forms only, and the submit lock. No ES modules: the file must run in a plain
  browser and in jsdom without a build step (`C-7`).
- `tests/browser/harness.js` - the browser runner: clones fixtures into a hidden stage, asserts, writes
  the report into `#harness-summary` / `#harness-report`, sets `window.harnessResult`, reruns on demand.
- `app/Controllers/BrowserTestsController.php` + `app/Views/tests/browser.php` + three routes - the
  development-only page `/tests/browser`. It renders inside the real layout (real `app.js`, real
  stylesheets, real CSRF token) and serves `harness.js` and `cases.js` from `tests/browser/`, so the
  test suite never has to be copied into `public/`.
- `tools/dev/browser-tests.mjs` - the jsdom runner: executes the same `app.js`, `cases.js`,
  `harness.js`, and exits 0/1/2. Documented in `tools/dev/README.md`, wired into `npm run check`.
- `public/assets/js/app.js` - the three `localizeNumbers` defects found while writing the cases (see
  ROOT CAUSES) and the API the cases assert on: `toLatinDigits` and `attachCsrfTokens` are now exported.
- `tests/Integration/BrowserHarnessTest.php` - five guard tests: the page works in development with a
  real token, both scripts are served byte-for-byte with `text/javascript` and `no-store`, all three
  paths are 404 in production, every fixture is used by exactly the cases that request it, and nothing
  from the test suite exists under `public/`.
- `docs/engineering/project-context.md` - `O-11` recorded as **decided (option 2)**, plus the sandbox
  fact that no real browser can be installed here.
- Deliberately not changed: no browser binary was downloaded, no dependency added beyond the
  development-only jsdom, and the design-system contract test that checks every `var(--token)` is
  defined was left in place - it immediately rejected two of my own CSS additions (fixed by using the
  existing tokens).

## ROOT CAUSES

Six defects were found by building the cases, not by reading the code. Each one produced a visible
symptom on the page a user sees:

1. `localizeNumbers` wrote `NaN` into an element when the marked attribute or the text held no number.
   Mechanism: `Number("")` and `Number("نامعلوم")` were formatted without a guard.
2. It threw inside `forEach` on a non-integer value, so one bad element aborted localization for the
   whole page. Mechanism: `formatInteger` throws by design (refusing to guess a rounded amount), and
   the DOM pass did not catch it per element.
3. It was not re-runnable: already-formatted Persian text (`۱٬۲۳۴`) could not be parsed back, so a
   second run on a dynamic page saw `NaN`. Mechanism: parsing only accepted Latin digits, while the
   studio re-runs this pass after every change.
4. The base script attached its `DOMContentLoaded` listener without checking whether the document had
   already loaded, so on a page that runs the script late (defer-less injection, jsdom) the listeners
   were never attached and the submit lock silently did nothing.
5. `attachCsrfTokens` was not reachable from outside the module, so no test could drive it.
6. `app.js` had no way to turn Persian or Arabic-Indic input back into a number, which is what the
   design studio needs for the values a user types.

Why they escaped: there was no test that executed the script at all - the PHP suite cannot observe a
browser, and no browser was available. The one class of defect a browser test actually catches is
exactly this one: behaviour that is invisible in the source and silent on the page.

The new fixture/case consistency test found one more defect in the harness itself, on its first run:
the `number-negative` fixture existed but no case exercised it. That is the test doing its job rather
than a surprise, and it is why the check exists (a fixture nothing runs is a behaviour nothing checks).

## TESTS EXECUTED

```bash
node tools/dev/browser-tests.mjs
node tools/dev/php.mjs test
node tools/dev/php.mjs test --filter=BrowserHarness
node tools/dev/php.mjs lint
node .agents/scripts/validate-engineering-os.mjs --strict
```

Mutation controls (a check that cannot fail is not a check). Both were applied to a copy, executed, and
reverted from the backup:

```bash
# Control 1: remove the submit lock from app.js
# Control 2: replace parseNumber() with Number() inside the DOM pass
```

## RESULTS

- `node tools/dev/browser-tests.mjs` → `Tests: 14 passed, 0 failed, 14 total (jsdom)`.
- `node tools/dev/php.mjs test` → `Tests: 196 passed, 0 failed, 931 assertions, 2246 ms`.
- `node tools/dev/php.mjs test --filter=BrowserHarness` → `Tests: 5 passed, 0 failed, 33 assertions`.
- `node tools/dev/php.mjs lint` → `checked 87 file(s), 0 failed` / `PHP lint passed.`
- Validator `--strict` → `9 checks passed, 0 warning(s), 0 error(s)` / `RESULT: PASS`.
- Control 1 → `FAIL فرم در حال ارسال، دکمه را قفل میکند...` / `Tests: 11 passed, 1 failed, 12 total`
  (the run at that moment had 12 cases; the negative-value case was added afterwards).
- Control 2 → `FAIL مقدار فارسی روی صفحه، در اجرای بعدی هم قالببندی میشود...` /
  `Tests: 12 passed, 1 failed, 13 total`, then `app.js` restored byte-identical (`diff -q` silent) and
  the suite green again.
- The design-system contract test rejected the first version of the harness stylesheet
  (`components.css uses --color-danger-surface, --color-danger-text`); after switching to the existing
  tokens: `Tests: 196 passed, 0 failed`.

## SECURITY REVIEW

- The harness page is development-only: `GET /tests/browser`, `/tests/browser/harness.js` and
  `/tests/browser/cases.js` each return **404** when `app.env` is `production` - tested, and the body
  is checked for leaked harness markers, not just the status code.
- Nothing from the test suite is reachable as a static file: the scripts are served through the
  controller, and a test walks `public/` asserting that no file named like a test asset exists there.
  This matters because a document root serves files, not routes: a copy inside `public/` would be
  reachable on a live host no matter what the router does.
- The page uses the real CSRF token and the real scripts, so the cases exercise the same code path a
  customer's browser loads - no second implementation exists to drift.
- No secrets, no credentials, no new outbound requests: jsdom is a development dependency, never loaded
  by the product. `npm install --save-dev jsdom` reported `0 vulnerabilities`.
- Input handling: the cases include hostile shapes (`نامعلوم`, empty string, `12.5`, Arabic-Indic
  digits, a form already carrying a token) precisely because these reach the DOM from user input.

## PERFORMANCE REVIEW

- No product code was added to the request path except three routes that 404 in production. The
  development page renders the style-guide-sized markup (17 KB of HTML in the snapshot) in one pass.
- The base script's DOM pass now skips values it cannot parse instead of throwing, so a page with one
  bad element no longer aborts, and re-running the pass converges (idempotence is asserted, not
  assumed).
- Test execution cost: the jsdom run takes ~0.7 s and adds no measurable time to `npm run check`
  (PHP lint 0.6 s, suite 2.2 s).
- No measurement of canvas/RTL rendering is possible here: there is no browser and no layout engine.

## REGRESSION REVIEW

- `app.js` is loaded by every page: the full suite, the lint pass and the validator were re-run after
  the change (`196 passed`, `0 failed`, `87 files`, `9 checks`). The existing design-system contract
  test (`window.chapino` presence, no CDN, self-hosted font, no build step) still passes.
- The `localizeNumbers` rewrite keeps the documented behaviour: unmarked numbers are untouched,
  invalid values are left exactly as the server wrote them, and grouping/separators are unchanged
  (`۱٬۲۳۴`, `۱٬۰۰۰٬۰۰۰`, `−۲٬۵۰۰`, `۱٬۲۳۴٫۵۷`).
- The dev-only route pattern was copied from the style guide rather than invented, so the
  production-404 rule and the "development tooling is not a feature" stance are unchanged.
- Both mutation controls were reverted and verified byte-identical before the suite was re-run.

## REMAINING RISKS

- **No real browser ran the page here.** jsdom executes JavaScript but has no layout engine, so
  nothing visual (fonts, RTL shaping, the submit spinner's position, the report's styling) is verified.
  The owner's browser is the only place this can be judged; the page is delivered for exactly that.
- The preview served to the owner in this session is a **static snapshot** of the real rendered HTML
  with a visible note, not a running PHP installation: form submission and live localization do not
  work in it. Final verification stays on the owner's localhost (XAMPP/Laragon/`php -S`).
- The cases pin behaviour, not appearance: a visually broken but behaviourally correct change would
  still pass. This is a deliberate trade-off for `O-11` option 2 (no install, no download).
- `jsdom` is now a development dependency of `tools/dev`. The product still runs on native PHP alone
  (ADR-0002); if `tools/dev` disappeared, only the convenience runner would.
- The harness serves its scripts with `no-store` and reads them per request: correct for development,
  and irrelevant in production where the paths 404.

## NEXT ACTION

Owner: open `/tests/browser` in a browser on a working installation and report the summary line and
any failed case by name (this is the step that closes `O-11`).
