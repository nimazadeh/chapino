# Engineering Report - <task title>

- Date: YYYY-MM-DD
- Workflow: `<feature-development | bug-fix | full-system-audit | security-audit | performance-audit | release-verification>`
- Branch: `<branch>`
- Revision verified: `<commit hash>` (working tree: clean | dirty - explain)
- Rules applied: `<rule ids>`

> Format and vocabulary: [.agents/rules/reporting.md](../../../.agents/rules/reporting.md).
> Every claim below must be backed by an executed command and its observed output.

---

## STATUS

`PASS` | `PASS WITH RISKS` | `BLOCKED` | `FAIL`

One sentence of justification. `PASS` is not available if anything in scope is unverified.

## IMPLEMENTED

- What changed, with file paths.
- What was deliberately **not** changed.

## ROOT CAUSES

For bugs: the actual cause at code level, the mechanism, and why it escaped detection.
Otherwise: "not applicable", or the defects discovered while working.

## TESTS EXECUTED

Exact commands, verbatim:

```bash
<command 1>
<command 2>
```

## RESULTS

The observed result of each command - counts, output excerpts, error text.
Real output only; never a described or assumed result.

## SECURITY REVIEW

- What was checked (input validation, authorization/IDOR, output encoding, CSRF, secrets, sessions,
  uploads, rate limiting, admin surface - as applicable).
- Outcome for each.
- Areas not applicable, and why.

## PERFORMANCE REVIEW

- What was checked (query count/N+1, indexes, bounds, payload size, assets, leaks).
- Numbers if measured, or an explicit statement that measurement was not possible here.

## REGRESSION REVIEW

- Which existing behaviour could be affected.
- Which existing checks were re-run, and their result.

## REMAINING RISKS

- Everything not verified, every assumption, every known limitation, every deferral.
- "None identified" is acceptable; omitting the section is not.

## NEXT ACTION

Only if genuinely necessary. Otherwise: "None."
