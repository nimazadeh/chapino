# Rule: Quality Assurance and Testing

**Rule ID:** `qa`
**Applies to:** every test written, every test claimed, every verification performed.
**Canonical owner of:** test strategy and coverage expectations, assertion integrity, verification
discipline, regression protection.
**See also:** [`debugging`](debugging.md), [`code-review`](code-review.md), [`reporting`](reporting.md), and the [release verification workflow](../skills/release-verification/SKILL.md).

---

## 1. Non-negotiable integrity rules

The agent must **never**:

- **Fake a test** - no test that asserts nothing, always passes, or tests a stub instead of the code.
- **Weaken an assertion** - no loosened matcher, no removed check, no broadened tolerance, to obtain
  a passing result.
- **Delete or skip a failing test** because it is inconvenient. A failing test is information.
- **Claim a test passed without executing it.** Output must be observed in this session.
- **Report a green suite that contains skipped, `todo` or pending tests without naming them.**
- **Mock away the thing under test** so that the test proves the mock works.
- **Write tests that assert implementation details** (private method names, internal call order,
  markup structure) instead of observable behaviour.

> Tests verify **behaviour**: given an input and a state, the system produces a specific observable
> result and side effect. A test that breaks when code is refactored without behaviour change is a
> poorly targeted test.

## 2. Test levels

Use the smallest level that proves the behaviour, and combine levels - never only one.

| Level | Proves | Notes |
| --- | --- | --- |
| Unit | One function/class obeys its contract, including boundaries | Fast, isolated, no I/O |
| Integration | Modules and the storage boundary work together | Real database or realistic substitute; covers transaction behaviour |
| API / contract | Endpoints honour request/response contracts and status codes | Includes validation, auth, not-found, error shapes |
| Database | Constraints, migrations, indexes and query behaviour | Migrations tested on a copy of realistic data, not an empty database |
| Component / DOM | Markup, state rendering and interaction logic in isolation | Asserts user-visible behaviour, not DOM trivia |
| End-to-end / browser | A real user journey works in a real browser | Covers the critical paths only; kept few and stable |
| Regression | A previously fixed defect stays fixed | Added with every bug fix, referencing the defect |
| Build verification | The project builds and starts from a clean state | Proves the artifact, not just the source |
| Security checks | Input handling, authorization boundaries, headers, secrets | See [`security`](security.md) |

**Priority order when time is limited:** critical path end-to-end > authorization and validation
boundaries > data integrity and transactions > error paths > everything else.

## 3. What every behaviour must be tested against

For each behaviour under test, cover the applicable rows:

| Dimension | Cases |
| --- | --- |
| Happy path | The normal, expected flow |
| Invalid input | Missing, wrong type, out of range, malformed, over-long, wrong shape |
| Empty state | Zero items, empty list, first-run state |
| Boundaries | Minimum, maximum, off-by-one, exactly-at-limit, one-past-limit |
| Authorization | Anonymous, wrong owner, insufficient role, unauthorized object access |
| Not found | Nonexistent id, deleted object, wrong parent |
| Failure | Dependency error, timeout, partial failure, server error |
| Concurrency | Duplicate/parallel requests, retry, double submission |
| Idempotency | Replaying the same operation produces the same state |
| Data integrity | Nothing partially written after a failure |
| Localization | Persian text, digits, dates, RTL handling where user-visible |

## 4. Test quality bar

- A test must fail for the right reason when the behaviour is broken - verify this by observing the
  failure, or state honestly that mutation testing was not performed.
- Test names describe behaviour and expectation, not the method name.
- Each test is independent: no order dependency, no shared mutable state, no reliance on clock or
  environment without control.
- Time, randomness, network and external services are controlled or injected.
- Fixtures and seeds contain no real personal data and no production credentials.
- Assertions are specific. `assertTruthy(result)` where a precise value is knowable is a defect.
- Failures produce actionable output: what was expected, what was received, with which input.

## 5. Verification discipline

1. Run the narrowest relevant check first; fix; then run the broader suite.
2. After any change that touches shared code, re-run the full relevant suite - not only the new test.
3. **Re-verify on the frozen diff.** The final claim refers to the exact revision that was tested;
   if anything changed afterwards, the verification is void.
4. Record the exact command and the exact observed result. Summaries without commands are not
   evidence.
5. If a check cannot run in this environment (no runtime, no database, no browser, no network), say
   so explicitly and mark the item unverified. Never approximate the result.
6. Flaky behaviour is a defect to be diagnosed with the [bug fix workflow](../skills/bug-fix/SKILL.md)
   - never a reason to retry until green.
7. When the test harness does not exist yet, do not invent test results. State that verification is
   limited to static review and manual execution, and name what a harness would need to cover.

## 6. Coverage expectations

- Every new endpoint: success, validation failure, unauthorized, not found, server error.
- Every new screen/state: the ten states in the [frontend rule](frontend.md#1-the-prime-directive).
- Every bug fix: a regression test that fails before the fix and passes after.
- Every migration: forward test (including with existing data) and, where feasible, reverse test.
- Every permission change: a test proving the denied path, not only the allowed one.

Coverage percentage is a weak signal. Uncovered **critical paths and authorization boundaries** are
the real defect.

## 7. Checklist

- [ ] The level of test is appropriate to the behaviour and the risk.
- [ ] Boundary, invalid, empty, unauthorized and failure cases covered where applicable.
- [ ] Assertions are precise and behavioural.
- [ ] Exact commands executed in this session, with recorded output.
- [ ] The full relevant suite was re-run, no test weakened, skipped or deleted.
- [ ] No fabricated or assumed result anywhere in the report.
- [ ] Unverifiable items explicitly listed as unverified.
- [ ] For a bug fix: a regression test exists and its pre-fix failure was observed.
