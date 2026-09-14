# Rule: Engineering Report Standard

**Rule ID:** `reporting`
**Applies to:** the end of every task, without exception.
**Canonical owner of:** the report format, status vocabulary, evidence requirements, prohibited claims.
**See also:** [`qa`](qa.md), [`code-review`](code-review.md), [`git-workflow`](git-workflow.md), template: [`docs/engineering/templates/report.md`](../../docs/engineering/templates/report.md).

---

## 1. Status vocabulary

Use exactly one, with this meaning:

| Status | Means |
| --- | --- |
| **PASS** | Everything in scope was implemented and verified; no known unresolved risk in the changed area. |
| **PASS WITH RISKS** | Implemented and verified as far as the environment allows; specific, named risks or unverified items remain. |
| **BLOCKED** | Cannot proceed or cannot verify. State the blocking condition, why it blocks, and what is needed to unblock. |
| **FAIL** | The result does not meet the requirement, or verification failed. State what failed with real output. |

`PASS` is never available when any item is unverified. "Probably fine" maps to `PASS WITH RISKS` at
best.

## 2. Report format

Every major task reports these sections, in this order:

```
STATUS
IMPLEMENTED
ROOT CAUSES
TESTS EXECUTED
RESULTS
SECURITY REVIEW
PERFORMANCE REVIEW
REGRESSION REVIEW
REMAINING RISKS
NEXT ACTION
```

| Section | Content |
| --- | --- |
| STATUS | One of the four statuses, plus one sentence of justification. |
| IMPLEMENTED | Exactly what changed, with file paths. Include what was deliberately **not** changed. |
| ROOT CAUSES | For bugs: the actual cause, at code level, with why it existed. For non-bug tasks: state "not applicable" or list defects discovered. |
| TESTS EXECUTED | The exact commands run, verbatim. Not descriptions of commands - the commands. |
| RESULTS | The observed result of each command: pass/fail counts, output excerpts, error text. Real output only. |
| SECURITY REVIEW | What was checked (input, authorization, output encoding, secrets, sessions, uploads, as applicable) and the outcome, including "not applicable because...". |
| PERFORMANCE REVIEW | What was checked (queries, N+1, bounds, payload, assets) and the outcome, or an honest statement that measurement was unavailable. |
| REGRESSION REVIEW | Which existing behaviour could be affected, and which existing checks were re-run. |
| REMAINING RISKS | Everything not verified, every assumption, every known limitation, every deferral. If empty, say "none identified" - do not omit the section. |
| NEXT ACTION | Only if genuinely necessary, and only what is required next. Never filler. |

## 3. Evidence rules

1. Every claim of completion is backed by an executed command and its observed output.
2. When no measurement was possible, say which tool/environment was missing.
3. Counts must be real: "۱۲ تست اجرا شد، همه پاس" is acceptable only if the runner output showed it.
4. Distinguish clearly: verified by execution / verified by inspection of code / assumed.
5. If a previous report's claim turned out to be wrong, correct it explicitly.

## 4. Prohibited phrases

Never write, or imply:

- "Everything should work."
- "Looks good."
- "Probably fixed."
- "Tests would pass."
- "Should be fine now."
- "No issues found" (without stating what was inspected and how).
- Any percentage of completeness or confidence that was not measured.

## 5. Language

Reports may be written in English or Persian. Standardized keywords (`STATUS`, `PASS`, file paths,
commands, identifiers) stay in English in both cases, because they are looked up and grepped.
If the requester writes in Persian, the prose sections are written in Persian.

## 6. Persisting the report

For any substantial task, save the report to
`docs/engineering/reports/YYYY-MM-DD-<task-slug>.md` using the template, and summarize it in the
response. Chat-only reports are acceptable for trivial tasks; repository reports are required for
features, bug fixes, audits and releases - they are the record a new engineer will read.

## 7. Checklist

- [ ] Exactly one status keyword used, and it is honest about what remains unverified.
- [ ] All ten sections present in the required order (inapplicable ones say so explicitly).
- [ ] `IMPLEMENTED` names the files changed, and what was deliberately left unchanged.
- [ ] `TESTS EXECUTED` contains literal commands; `RESULTS` contains literal observed output.
- [ ] Every completion claim is backed by execution, inspection or an explicit "assumed" label.
- [ ] `REMAINING RISKS` is present and truthful; "none identified" is written when that is the case.
- [ ] No prohibited phrase ("should work", "probably fixed", "looks good") appears anywhere.
- [ ] `NEXT ACTION` is omitted or empty when nothing genuinely requires action.
- [ ] For a defect: `ROOT CAUSES` describes the cause, not the symptom, and the fix category is
      stated (root-cause fix / mitigation / not reproducible).
- [ ] The report was saved under `docs/engineering/reports/` for substantial work, and the index in
      that directory was updated.
