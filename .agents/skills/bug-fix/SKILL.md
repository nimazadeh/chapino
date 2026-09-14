---
name: bug-fix
description: Systematic defect resolution procedure - reproduce, define expected versus actual behaviour, trace through the real layers, identify the root cause, fix the cause, add a regression test, search for related defects, re-run related and broader tests, then verify on the frozen diff. Use whenever something is broken, failing, flaky, regressed or behaving unexpectedly.
license: Proprietary. See LICENSE if present; otherwise all rights reserved by the repository owner.
metadata:
  owner: chapino
  kind: workflow
  version: "1.0"
---

# Workflow: Bug Fix

Announce at the start: `WORKFLOW: bug-fix`.

Governing rules: [debugging](../../rules/debugging.md) (mandatory sequence),
[qa](../../rules/qa.md), [security](../../rules/security.md), [code-review](../../rules/code-review.md),
[reporting](../../rules/reporting.md).

> Never change code repeatedly until the error disappears. That is not debugging.

---

## Step 1 - REPRODUCE

- Obtain a minimal, deterministic reproduction: exact input, exact steps, exact environment.
- Prefer a failing automated test over a manual description; if the harness exists, write that test.
- Record: command, input, observed output (pasted, not paraphrased).
- If it cannot be reproduced: record every condition (environment, data, timing, version) and what
  was tried. Do **not** claim a fix. Report `BLOCKED` with a hypothesis.

**Output:** a reproduction artifact and its exact output.

## Step 2 - DEFINE EXPECTED BEHAVIOUR

- What does the requirement, contract or rule say should happen?
- Cite the source (requirement text, documented contract, existing convention, test).
- If the expectation is unclear, that ambiguity is itself the finding - ask.

**Output:** one precise sentence: "expected X when Y".

## Step 3 - DEFINE ACTUAL BEHAVIOUR

- Paste the real error message, stack trace, response body, or wrong value.
- Note the exact version/revision where it happens.
- Distinguish the symptom the user sees from the underlying failure.

**Output:** "actual: Z", with raw evidence.

## Step 4 - TRACE

Follow the data, do not guess. Use the layer-by-layer trace in the
[debugging rule](../../rules/debugging.md#4-trace-technique-for-this-stack-framework-free-php--vanilla-js):

browser → server entry → middleware → request parsing → validation → authorization/session →
domain logic → storage (query, parameters, plan, rows, transaction outcome) → response → client render.

Rules:
- Verify each hop with real evidence (log line, query log, devtools).
- Change one thing at a time when experimenting.
- Find **where the value first becomes wrong** - that is the boundary of the defect.
- Eliminate the obvious distractors: stale cache, un-run migration, wrong configuration, wrong
  branch, wrong database, encoding issues with Persian text.

**Output:** the exact location where correct input becomes incorrect behaviour.

## Step 5 - IDENTIFY ROOT CAUSE

State it as: cause → mechanism → impact. For example:

> The ownership check compared `user_id` from the session against a *client-supplied* `owner_id`
> field, so any authenticated user could read another user's record by changing that field.

- Explain why the code was written this way and why it was not caught (missing test, ambiguous
  contract, shared assumption).
- If the cause is environmental rather than code, say so explicitly.
- "Something was wrong with the query" is not a root cause.

**Output:** the root cause, at code level, plus why it escaped detection.

## Step 6 - FIX

- Fix at the layer where the cause lives, not where the symptom appears.
- Prefer the smallest correct fix; do not smuggle in refactoring or unrelated improvement.
- If a temporary mitigation is unavoidable (external outage), state the real cause, mark the
  mitigation temporary, record a follow-up, and never call it a fix.
- Re-read the [symptom-patch prohibitions](../../rules/debugging.md#2-symptom-patches-are-prohibited).

**Output:** a focused diff addressing the cause.

## Step 7 - REGRESSION TEST

- Write a test that **fails before the fix and passes after** the fix.
- Observe and record the pre-fix failure. If you cannot observe it (for example, you already applied
  the fix), revert temporarily, capture the failure, then re-apply.
- The test must assert behaviour, not implementation detail.
- Name it after the defect so the reference is discoverable.

**Output:** test name, pre-fix failure output, post-fix pass output.

## Step 8 - SEARCH FOR RELATED DEFECTS

Ask explicitly: where else does this same cause exist?

- The same pattern copy-pasted in sibling code.
- The same missing check on other resources (another IDOR, another unvalidated field).
- The same broken assumption in another layer or another endpoint.
- Other callers of the function whose contract was misused.

Report every instance found with file path and severity, even when out of scope. Fix in-scope ones;
list the rest under `REMAINING RISKS`.

**Output:** a list (possibly empty, stated as checked) of related instances.

## Step 9 - RUN RELATED TESTS

- Run every test in the touched area, not only the new regression test.
- Confirm the pre-existing suite is still green (or that the same known failures remain, unchanged).

**Output:** command and result.

## Step 10 - RUN BROADER TESTS

- Run the wider relevant suite and the critical user journeys.
- Re-run anything whose behaviour depends on the changed code path.
- Where no automated suite exists, perform and record the manual verification of the affected flows.

**Output:** command and result, or a named gap.

## Step 11 - VERIFY

On the frozen diff:

- Re-run the full relevant suite once more.
- Confirm the original reproduction now produces the expected behaviour (reproduce the fix, not just
  the test).
- Confirm the working tree contains no debug artifacts (`git status`, `git diff`).
- Perform the [code review checklist](../../rules/code-review.md#3-review-checklist).

**Output:** verification evidence tied to the exact revision.

## Step 12 - REPORT

Produce the [engineering report](../../rules/reporting.md#2-report-format), with `ROOT CAUSES` filled in
properly and the fix classified honestly as one of:

- root-cause fix
- root-cause fix + related defects found (listed)
- temporary mitigation (cause stated)
- not reproducible (conditions and hypothesis recorded, no fix claimed)

Save to `docs/engineering/reports/` for non-trivial defects.

---

## Fast path

Only for a **trivial, isolated** defect (for example, a typo in a user-visible Persian string, or a
wrong constant with no side effects):

1. Reproduce and state expected vs. actual.
2. Identify the cause (one line of reasoning is acceptable if the code proves it).
3. Fix, add/extend a test if a harness exists.
4. Re-run the narrow tests and `git diff` review.
5. Report with all report sections, marking the ones that were genuinely not applicable.

The fast path never applies to anything touching authorization, money, personal data, data writes,
concurrency or shared contracts.
