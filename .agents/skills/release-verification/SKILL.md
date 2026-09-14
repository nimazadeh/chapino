---
name: release-verification
description: Pre-release verification gate that runs tests, lint and type checks, the production build, configuration validation, migration checks, security checks, end-to-end and browser tests, critical user journeys, error handling verification, production-mode checks, git diff review and regression review, then produces a release report with an explicit go or no-go verdict. Use before any release, tag, deploy or question about whether something is ready.
license: Proprietary. See LICENSE if present; otherwise all rights reserved by the repository owner.
metadata:
  owner: chapino
  kind: workflow
  version: "1.0"
---

# Workflow: Release Verification

Announce at the start: `WORKFLOW: release-verification`.

Governing rules: [qa](../../rules/qa.md), [security](../../rules/security.md),
[git-workflow](../../rules/git-workflow.md), [code-review](../../rules/code-review.md),
[database](../../rules/database.md), [performance](../../rules/performance.md),
[reporting](../../rules/reporting.md).

> A release is ready only when the evidence below exists. Missing tooling is not a pass - it is a
> named risk that forces `PASS WITH RISKS` or `BLOCKED`.
> Nothing may be edited during this workflow. If a step fails and a fix is required, stop, fix it
> through the normal workflow, and restart release verification from step 1 on the new revision.

---

## Step 0 - Freeze the release candidate

- Record: branch, commit hash, tag (if any), working-tree cleanliness (`git status` must be clean or
  the difference explicitly accounted for).
- Confirm the exact artifact that will be released, and how it is produced.
- Record the environment being verified (local sandbox, staging, production-like) and its limits.

**Exit:** a frozen revision hash, referenced in every result below.

## Step 1 - Run the tests

- Execute the full relevant automated suite with the project's real command.
- Record exact commands and exact results, including counts and any skipped tests.
- Any failure = do not proceed to declare readiness; classify as release blocker or a named known
  failure explicitly accepted by the owner.
- If no automated suite exists, state it plainly as an unverified area (this is a very common cause
  of `PASS WITH RISKS`).

## Step 2 - Run lint / type / syntax checks

- Run whatever static checking the project has (syntax checks, linters, formatters in check mode,
  type analysis).
- Record the command and its real output, including warnings, not only errors.
- If none exist, say so and record it as a gap.

## Step 3 - Build the project

- Produce the artifact exactly as production produces it, with documented commands.
- Confirm the build is reproducible from a clean state (no reliance on stale artifacts or local
  state).
- Record the artifact size and content where it is meaningful.
- Build warnings are part of the result; report them.

## Step 4 - Validate configuration

- Confirm every required configuration value is documented and present in the target environment.
- Confirm no development-only default is active in production (debug output, verbose errors,
  permissive cross-origin policy, disabled security checks, test credentials, sample data).
- Confirm secrets come from the environment or a secret store and are absent from the repository and
  from the built artifact.
- **Never print secret values** in the report; reference the key names only.

## Step 5 - Check migrations

- List the migrations included in this release and confirm each has been reviewed against the
  [migration safety checklist](../../rules/database.md#5-migrations).
- Confirm the order and that they have been tested against a copy of realistic data (or state that
  they have not).
- Confirm the backup and restore path exists for anything destructive, and that rollback is possible
  or that its absence is explicitly accepted.
- Confirm the application code is compatible with both the pre-migration and post-migration schema
  where the release is not applied atomically.

## Step 6 - Run security checks

- Run the [security audit workflow](../security-audit/SKILL.md) at the scope appropriate to the
  release (at minimum: authentication, authorization on changed areas, input validation, output
  encoding, secrets, error verbosity, rate limiting on changed endpoints).
- Any CRITICAL finding blocks the release unless the owner explicitly accepts it in writing, and it
  is recorded in the report as an accepted risk.
- Record what was checked and found clean, and what could not be checked here.

## Step 7 - Run end-to-end / browser tests

- Execute the available browser-level tests over the critical journeys.
- If no browser automation exists, perform and document a manual pass of each critical journey,
  stating that it was manual.
- Verify at least once in a real browser with real Persian content: RTL layout, Persian digits and
  dates, labels, and error messages.

## Step 8 - Check critical user journeys

For each journey, walk it as a user and record the outcome:

1. First visit / landing.
2. Registration or entry into the product.
3. Authentication (success and failure).
4. The primary value-producing action of the product.
5. Any action involving data mutation, upload or payment.
6. Permission boundaries: what an unauthorized or lower-privilege user sees and whether they are
   correctly blocked.
7. Error recovery: what happens when a step fails.
8. Logout and session expiry behaviour.
9. The recovery paths: forgotten credentials, retry after failure, cancel a destructive action.

Each journey must be verified in every applicable state: success, error, loading, empty, invalid
input, unauthorized, forbidden, not found, server error, slow network.

## Step 9 - Check error handling

- Trigger representative failures (invalid input, unauthorized request, missing record, dependency
  failure where feasible) and confirm the user sees a safe, Persian, actionable message and no
  internal detail.
- Confirm the server log records the failure with enough context to diagnose it, and no sensitive
  data.
- Confirm failures do not leave partial data, orphaned records or a broken UI state.

## Step 10 - Check the production build

- Start the production artifact, not the development server, and re-run the critical journeys
  against it at least once.
- Confirm the production artifact serves the same behaviour as development, with debug surfaces
  absent.
- Confirm asset delivery works (compression, caching headers, correct content types, correct charset
  for Persian text).

## Step 11 - Review the git diff

- Review the complete diff for this release (`git diff`, `git log`, `git status`).
- Confirm only intended changes are present: no debug output, no temporary files, no secrets, no
  build output, no unrelated refactor, no commented-out code, no dependency directory, no editor
  noise.
- Confirm commit messages are meaningful and the history reflects the work.
- Confirm the release revision matches what was verified in steps 1-10.

## Step 12 - Review for regressions

- Identify everything this release touches and what could break because of it.
- Re-run the tests covering those areas, plus the critical journeys already exercised.
- Compare against the previous release's known behaviour: anything that used to work and now does not
  is a blocker unless explicitly accepted.

## Step 13 - Final release report

Produce `docs/engineering/reports/YYYY-MM-DD-release-verification-<version>.md` containing all ten
[report sections](../../rules/reporting.md#2-report-format), plus:

- `RELEASE CANDIDATE`: artifact, branch, commit hash, tag.
- `VERIFICATION CHECKLIST`: each step 1-12 with `executed` / `not possible`, the exact command, and
  the observed result.
- `BLOCKERS`: anything that prevents release.
- `ACCEPTED RISKS`: risks the owner explicitly accepted, with who accepted them.
- `ROLLBACK PLAN`: how to return to the previous release, including data considerations.
- `GO / NO-GO VERDICT`:

| Verdict | Condition |
| --- | --- |
| **GO** | Every applicable step executed and passed; no unresolved blocker; risks listed and accepted. |
| **GO WITH RISKS** | Only non-blocking, explicitly listed risks or unverifiable steps remain. |
| **NO-GO** | A test, build, migration, security or journey check failed, or a CRITICAL security finding is open. |

Then state the verdict and the evidence in the response. Never declare readiness that the evidence
does not support, and never soften a `NO-GO` into "should be fine".
