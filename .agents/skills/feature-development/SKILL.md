---
name: feature-development
description: End-to-end procedure for implementing new functionality in this repository, from requirement discovery through architecture, plan, implementation, testing, debugging, security and performance review, regression checking, final verification and reporting. Use whenever the user asks to build, add, create or extend a feature or endpoint or screen.
license: Proprietary. See LICENSE if present; otherwise all rights reserved by the repository owner.
metadata:
  owner: chapino
  kind: workflow
  version: "1.0"
---

# Workflow: Feature Development

Announce at the start: `WORKFLOW: feature-development` and list the phases you will run.

Rules that always apply during this workflow:
[architecture](../../rules/architecture.md), [backend](../../rules/backend.md),
[frontend](../../rules/frontend.md), [database](../../rules/database.md), [qa](../../rules/qa.md),
[security](../../rules/security.md), [performance](../../rules/performance.md),
[localization](../../rules/localization-fa.md), [code-review](../../rules/code-review.md),
[git-workflow](../../rules/git-workflow.md), [reporting](../../rules/reporting.md).

---

## Phase 1 - DISCOVERY

- Restate the requirement in your own words, in one paragraph.
- List what is explicitly **out of scope**.
- List every unknown that changes the implementation (domain rules, data, permissions, providers,
  formats). **Ask**; do not invent. If a wrong guess is cheap to undo and reversible, mark it as an
  assumption and proceed - and say so.
- Identify the user-visible behaviour: what the user sees on success, on failure, on empty data.
- Identify who is allowed to do this, and who is not.
- Check [`docs/engineering/project-context.md`](../../../docs/engineering/project-context.md) for
  constraints and open decisions that affect this feature.

**Exit:** requirement, scope boundary, acceptance criteria, and open questions are written down.

## Phase 2 - REPOSITORY ANALYSIS

- `git status`, current branch, recent history.
- Read the areas that will be affected: routing, services, storage layer, templates/assets, config,
  tests, docs.
- Find the existing pattern for the closest equivalent feature and follow it.
- Identify reusable code before writing new code.
- Identify existing tests and the exact command that runs them.
- Identify shared contracts this change can break.

**Exit:** an accurate map of affected files and conventions, based on what was actually read.

## Phase 3 - ARCHITECTURE

- Decide where the behaviour belongs (which layer, which module, which endpoint).
- Define the interfaces: inputs, outputs, error shapes, storage shapes, and the validation boundary.
- Check the [architecture rule's checklist](../../rules/architecture.md#8-checklist); justify anything
  new.
- Identify the migration/backfill needs and read the
  [database migration safety checklist](../../rules/database.md#5-migrations).
- Identify the security-relevant surfaces (input, identity, permissions, files, personal data).
- Decide what will **not** be built now, and record it.

**Exit:** the design is written down, with the rejected alternatives and the reason for rejection.

## Phase 4 - PLAN

Before editing a single file, write and share a plan containing:

1. Files to create or modify (paths).
2. Order of work, in increments that each leave the codebase coherent.
3. Data changes: schema, migration, backfill, index.
4. Interfaces: endpoint paths and payloads, or DOM/state contracts.
5. UI changes and every state affected (success, error, loading, empty, invalid, unauthorized,
   forbidden, not found, server error, slow network).
6. Tests to add and the exact commands that will run them.
7. Localization items (Persian strings, dates, numerals, direction).
8. Risks, assumptions and rollback.

**Exit:** the plan is visible to the user, in the response, before implementation.

## Phase 5 - IMPLEMENTATION

- Implement in small increments; each increment must be coherent and reviewable.
- Follow existing conventions; do not introduce a second way of doing anything.
- Validate at every boundary; never trust client input.
- Write the server-side control, not only the browser-side check.
- Keep the diff focused: no unrelated refactor, no formatting churn, no dead code, no debug output.
- Add or update tests alongside each increment, not at the end.
- If an increment reveals a deeper problem (a bug, a missing constraint, a design flaw), stop and
  report it before continuing.

**Exit:** implementation complete, diff reviewable, no leftover scaffolding.

## Phase 6 - TESTING

- Run the narrowest relevant tests first, then the wider suite.
- Test normal behaviour **and** abnormal behaviour: invalid input, empty data, unauthorized access,
  forbidden access, missing record, dependency failure, boundary values, duplicate submission.
- Test the Persian-facing behaviour: RTL rendering, digits, dates, error text.
- Record the exact command and the exact output for each run.
- Do not weaken, skip or delete any test to obtain a pass.

**Exit:** every claimed behaviour has an executed test or an explicit "unverified" statement.

## Phase 7 - DEBUGGING

For every failure discovered, follow the [bug fix workflow](../bug-fix/SKILL.md) method:
reproduce → expected vs. actual → trace → root cause → fix → regression test → related tests →
broader tests → verify. Never proceed with a faked or skipped failure.

**Exit:** no known failure left unexplained.

## Phase 8 - SECURITY REVIEW

- Walk the [security rule's required areas](../../rules/security.md#2-required-review-areas) that this
  feature touches, and state each outcome.
- Specifically verify: authorization per resource (IDOR), server-side validation, output encoding
  for its context, CSRF on state-changing operations, no secret in the diff, rate limiting where the
  feature is abusable.
- State explicitly which areas were **not** applicable and why.

**Exit:** written security outcome, including any finding with severity.

## Phase 9 - PERFORMANCE REVIEW

- Walk the [performance rule's checklist](../../rules/performance.md#7-checklist) for what this feature
  introduces: query count, indexes, bounds, payload size, assets, main-thread work, leaks.
- Measure where measurement is possible; state where it is not.

**Exit:** written performance outcome, with numbers or an explicit "not measured".

## Phase 10 - REGRESSION

- Identify every existing behaviour the change could affect.
- Re-run the existing tests covering those areas.
- Manually exercise the closest neighbouring user journeys.
- Re-check the shared contracts the change touched.

**Exit:** regression statement: what could break, what was re-run, what the result was.

## Phase 11 - FINAL VERIFICATION

On the **frozen diff** (nothing edited after this point):

1. Re-run the full relevant test suite.
2. Run lint / syntax / static checks if the project has them.
3. Run the build, if the project has a build step.
4. Confirm no debug output, no commented-out code, no leftover temporary files remain
   (`git status`, `git diff`).
5. Perform the [code review rule's](../../rules/code-review.md) checklist and the ten adversarial
   questions.
6. Verify the exact revision being verified (branch and working tree state).

**Exit:** verification results recorded against a specific revision. Any edit after this void the
verification - re-run it.

## Phase 12 - REPORT

Produce the [engineering report](../../rules/reporting.md#2-report-format) with all ten sections. Save
it to `docs/engineering/reports/YYYY-MM-DD-<feature-slug>.md` for features.

Include: status, files changed, commands, results, security/performance/regression outcomes,
remaining risks, next action.

**Exit:** report delivered; commit made only if the user asked for a commit, following the
[git workflow rule](../../rules/git-workflow.md).
