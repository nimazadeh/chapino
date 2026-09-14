# Rule: Code Review

**Rule ID:** `code-review`
**Applies to:** every change, before it is declared complete.
**Canonical owner of:** the self-review obligation, review checklist, adversarial self-critique.
**See also:** [`qa`](qa.md), [`architecture`](architecture.md), [`security`](security.md), [`performance`](performance.md), [`git-workflow`](git-workflow.md).

---

## 1. The obligation

Before declaring any change complete, review it **as if another senior engineer had submitted it as
a pull request and you were the reviewer with authority to reject it**. You are looking for reasons
the change is wrong or incomplete, not for reassurance.

Also review the **diff**, not the files from memory: read `git diff` (including staged and untracked
changes) and confirm that what you are reviewing is exactly what will be committed.

## 2. Adversarial self-critique (mandatory)

Answer every question, explicitly, in the review step:

1. Under what conditions would this implementation fail?
2. What input, state, ordering or timing did I not test?
3. Which assumption in this change is unverified?
4. What did I break that used to work?
5. What happens at the boundary (zero, one, many, maximum, negative, duplicate, missing)?
6. What happens when the dependency I call fails, times out or returns garbage?
7. Who should not be able to do this, and can they?
8. If two requests arrive simultaneously, what is the outcome?
9. How will this behave in a year, with ten times the data?
10. What would a hostile reviewer attack first in this diff?

If you cannot answer a question, mark it as an unverified risk in the report. Do not paper over it.

## 3. Review checklist

**Correctness**

- [ ] The change does what the requirement asks, and nothing more.
- [ ] Logic handles the boundary and empty cases.
- [ ] No off-by-one, no inverted condition, no wrong default.
- [ ] Nullability, missing values and type coercions are explicit and safe.
- [ ] Errors are handled; nothing is silently swallowed.
- [ ] The change works for the failure path, not only the happy path.

**Architecture**

- [ ] Respects existing boundaries and dependency direction; no new cycles.
- [ ] No abstraction added without a present need; no framework-shaped code.
- [ ] No parallel implementation of something that already exists.
- [ ] Compatible with existing contracts; breaking changes are deliberate and stated.

**Maintainability**

- [ ] Readable by someone who has never seen the file; intent is explicit.
- [ ] Names describe purpose, not mechanism; no cryptic abbreviations.
- [ ] No duplicated logic introduced (especially business rules).
- [ ] Comments explain *why*, not *what*; no commented-out code committed.
- [ ] Dead code, debug output and temporary scaffolding removed.

**Security**

- [ ] Input validated server-side; output encoded for its context.
- [ ] Authorization checked per resource, not just per route.
- [ ] No secret, credential or personal data added to code, logs or fixtures.
- [ ] File, path, query and command construction cannot be influenced unsafely.
- [ ] New dependency: justified, pinned, licence-checked, or avoided.

**Performance**

- [ ] No N+1, no unbounded query or loop, no unnecessary re-computation.
- [ ] Indexes exist for new access patterns.
- [ ] No payload or asset bloat introduced; no blocking work on the user's path.

**Tests**

- [ ] Behavioural tests added or updated for the new/changed behaviour.
- [ ] Invalid, unauthorized, empty and failure cases covered where applicable.
- [ ] No test weakened, skipped or deleted; exact commands executed and results recorded.

**Regression and scope**

- [ ] Unrelated behaviour untouched; the diff contains only this change.
- [ ] Existing checks re-run in the affected areas.
- [ ] Formatting/whitespace churn does not obscure the real diff.

**Edge cases**

- [ ] Concurrent access, retry and double submission considered.
- [ ] Timezone, locale, RTL and large values considered where relevant.
- [ ] Partial failure leaves no inconsistent state.

**Backwards compatibility**

- [ ] Consumers of changed contracts identified and updated or intentionally broken with notice.
- [ ] Data written before this change still reads correctly after it.

**Naming and consistency**

- [ ] Follows repository conventions; if a convention had to be introduced, it is recorded in the
      documentation.

**Error handling and observability**

- [ ] Failures are visible and diagnosable; correlation identifier present where the environment has
      one.
- [ ] No verbose internal detail exposed to users; no useful detail erased from logs.

## 4. Verdict

Every review ends with one of:

- **APPROVE** - all checks pass, evidence recorded, remaining risks named.
- **APPROVE WITH RISKS** - acceptable to proceed, with explicitly listed risks and follow-ups.
- **REQUEST CHANGES** - issues must be fixed before the change is considered complete.

The verdict is part of the report (see [`reporting`](reporting.md)); it cannot be silently omitted.
Self-review may never be skipped because "the change is small" - small changes cause large outages.
