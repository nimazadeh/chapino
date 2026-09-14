# Rule: Debugging

**Rule ID:** `debugging`
**Applies to:** every defect, failure, flake, regression and unexplained behaviour.
**Canonical owner of:** the ten-step debugging method, root-cause discipline, related-defect search.
**See also:** the [bug fix workflow](../skills/bug-fix/SKILL.md), [`qa`](qa.md), [`security`](security.md).

---

## 1. The mandatory sequence

```
1  REPRODUCE
2  DEFINE EXPECTED BEHAVIOUR
3  DEFINE ACTUAL BEHAVIOUR
4  TRACE
5  IDENTIFY ROOT CAUSE
6  FIX ROOT CAUSE
7  ADD REGRESSION TEST
8  RUN RELATED TESTS
9  RUN BROADER TESTS
10 VERIFY
```

Steps may be skipped only if provably inapplicable, and the skip must be named in the report.

| Step | Required outcome (evidence) |
| --- | --- |
| 1 Reproduce | A minimal, repeatable reproduction. If not reproducible, record exactly which conditions block it and what was tried - never close an unreproduced bug silently. |
| 2 Expected | The behaviour the requirement or contract demands, stated precisely. |
| 3 Actual | The observed behaviour, with the real output pasted in. |
| 4 Trace | Follow the data from input to failure. Where does the value first become wrong? |
| 5 Root cause | The specific line/condition/ordering/assumption that causes it, plus why it was not caught. "Something was wrong with the query" is not a root cause. |
| 6 Fix | Fix the cause at the right layer. |
| 7 Regression test | A test that fails before the fix and passes after - the pre-fix failure must be observed. |
| 8 Related tests | All tests in the touched area re-run. |
| 9 Broader tests | The wider relevant suite, plus the critical journeys that could be affected. |
| 10 Verify | On the frozen diff: exact commands, exact results. |

## 2. Symptom patches are prohibited

When the root cause is reachable:

- Do not add a guard that hides the broken value; fix what produces it.
- Do not wrap the failing call in a broad `try/catch` that swallows the failure.
- Do not disable or delete the check, validation, test or feature that revealed the problem.
- Do not add a sleep, retry or "usually works" workaround around a race condition.
- Do not relax an assertion to match buggy behaviour.
- Do not adjust the test data so the bug stops appearing.

If a temporary mitigation is genuinely unavoidable (for example, an outage in a system you do not
control), then: state the real root cause anyway, mark the mitigation as temporary, record a
follow-up item, and never present the mitigation as a fix.

## 3. Investigate before changing anything

- Read the failing code path fully before editing a line.
- Reproduce first; a fix without a reproduction cannot be verified.
- Form a hypothesis and test it with the cheapest possible experiment (a log line, a narrow test, a
  query).
- Change one thing at a time. Multiple simultaneous changes destroy causality.
- Distinguish correlation from cause; a change that made the symptom disappear is not automatically
  the cause.
- Read the actual error text and stack; do not act on the assumed error.
- Check the obvious first: wrong environment, stale cache, wrong branch, un-run migration, wrong
  configuration, stale build artifact, wrong database, missing seed.

## 4. Trace technique for this stack (framework-free PHP + vanilla JS)

Frontend to backend, in order:

1. **Browser**: network panel request/response (real payload and headers), console errors, the exact
   value sent.
2. **Server entry**: request routing and middleware order; is the request reaching the handler at
   all?
3. **Request parsing**: `php://input`, `$_GET`/`$_POST`, headers, cookies - what did the server
   actually receive?
4. **Validation**: did the value get rejected or mutated?
5. **Authorization / session**: is the actor who you think, with the permissions you expect?
6. **Domain logic**: the decision branch taken, and the values at the branch.
7. **Storage**: the generated query, its parameters, its plan, the rows returned and the rows
   written; transaction commit or rollback.
8. **Response**: status code, headers, body encoding (Persian text must be UTF-8 and the declared
   content type must match).
9. **Client render**: what the browser did with the response, including JSON parse failures, CSP
   blocks, and errors thrown inside a handler that leaves the UI in a stale state.

Use the environment's real tooling (error log, `display_errors` **off** with logging on, structured
logs, database query log or profiler, browser devtools). Never debug by guessing at random.

## 5. Search for related defects

After fixing a root cause, ask: **where else does this same cause exist?**

- The same function or pattern copy-pasted elsewhere.
- The same assumption replicated in a sibling endpoint, form or job.
- The same class of bug in a different layer (frontend trusted a value the backend also trusted).
- The same missing check for a different resource (another IDOR next door).
- Other callers of the function whose contract was misused.

Report every instance found. Fix the in-scope ones; report the rest with file paths and severity.

## 6. Classify the fix honestly

In the report, say which category applies:

- **Root-cause fix** - the cause was removed.
- **Root-cause fix + related defects found** - list them.
- **Temporary mitigation** - cause identified and stated, mitigation scoped and time-boxed.
- **Not reproducible** - conditions recorded, hypothesis stated, no fix claimed.

Never describe a mitigation or a non-fix as "fixed".

## 7. Checklist

- [ ] Reproduced (or conditions for non-reproduction recorded).
- [ ] Expected vs. actual stated with real output.
- [ ] Trace performed through the real layers, not by assumption.
- [ ] Root cause named at the level of code and reason.
- [ ] Fix applied at the right layer; no symptom patching.
- [ ] Regression test added, pre-fix failure observed.
- [ ] Related defects searched and reported.
- [ ] Related and broader tests re-run with recorded results.
- [ ] Verification re-run on the frozen diff.
- [ ] Report states the fix category honestly.
