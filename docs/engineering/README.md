# The Engineering Operating System

This document explains how engineering is performed in this repository: what the rules are, what the
workflows are, when each is used, how work is verified, and how results are reported. It is written
for a new engineer (human or agent) joining the project with no prior context.

- **Constitution:** [`AGENTS.md`](../../AGENTS.md) - read this first.
- **Rules (always on):** [`.agents/rules/`](../../.agents/rules/)
- **Workflows (executable):** [`.agents/skills/`](../../.agents/skills/)
- **Project context and open decisions:** [project-context.md](project-context.md)
- **Task reports:** [reports/](reports/)
- **Templates:** [templates/](templates/)

---

## 1. Why this layer exists

Writing code is not the same as completing work. This repository encodes the difference: it defines
what must be inspected, planned, implemented, tested, debugged, reviewed, verified and reported for
every change - so that the result is professional engineering rather than plausible-looking output.

Two ideas hold the whole system together:

1. **Evidence.** Nothing is claimed as working unless a command was executed and its real output was
   observed. Unverifiable work is reported as unverified, never as done.
2. **Separation of responsibilities.** Obligations (rules) and procedures (workflows) are different
   things and live in different places, so neither is buried inside the other.

> **Current state of this repository:** the engineering governance layer only. There is no product
> code, no framework, no schema, no API and no UI yet. Product requirements arrive later, and the
> engineering system described here governs that work when it starts.

## 2. How to operate (the loop)

Every task runs through:

```
UNDERSTAND -> INSPECT -> ANALYZE -> PLAN -> IMPLEMENT -> TEST -> DEBUG -> REVIEW -> VERIFY -> REPORT
```

The practical meaning of each phase, and its exit condition, is defined in
[AGENTS.md section 3](../../AGENTS.md#3-mandatory-operating-loop). Two things are worth repeating
here because they are the most common failure modes:

- **INSPECT before editing.** Read the actual repository state; never assume it. Assumptions about
  existing code are the single largest source of damage an agent can do.
- **PLAN before implementing**, and share the plan. A plan that is visible can be corrected cheaply;
  an implementation that is wrong is expensive.

Skipping a phase is allowed only when it is provably inapplicable, and the skip must be named in the
report.

## 3. Rules - what each one does and when it applies

Rules are **always-on obligations**: constraints that hold for every change in their area. They do
not describe steps; they describe what must be true. Located in [`.agents/rules/`](../../.agents/rules/).

| Rule | File | What it governs | Load it when |
| --- | --- | --- | --- |
| Architecture | [architecture.md](../../.agents/rules/architecture.md) | Boundaries, layering, modularity, coupling, dependency direction, contracts, compatibility, ADRs | You touch boundaries, modules, dependencies or contracts, or add a subsystem |
| Backend | [backend.md](../../.agents/rules/backend.md) | API design, business logic, validation, auth, middleware, transactions, jobs, caching, errors, logging, rate limiting, the defect-hunt list | Any server-side change |
| Frontend | [frontend.md](../../.agents/rules/frontend.md) | Markup, component structure, state, API integration, forms, accessibility, responsiveness, the ten UI states, client performance | Any HTML/CSS/browser-JS change |
| Database | [database.md](../../.agents/rules/database.md) | Schema design, keys, constraints, indexes, queries, migration safety, data handling and retention | Any schema, migration, index, constraint or query change |
| QA | [qa.md](../../.agents/rules/qa.md) | Test levels, what must be covered, assertion integrity, verification discipline, coverage expectations | Writing tests or verifying anything |
| Debugging | [debugging.md](../../.agents/rules/debugging.md) | The ten-step debugging sequence, symptom-patch prohibitions, tracing, related-defect search | Any defect, failure, flake or unexplained behaviour |
| Security | [security.md](../../.agents/rules/security.md) | Trust boundary, required review areas, severity model, secret handling, sessions, regional constraints | Any change touching input, identity, permissions, files, money, personal data or admin surface |
| Performance | [performance.md](../../.agents/rules/performance.md) | Measurement discipline, query/request/asset/rendering performance, leaks, honest reporting | Any query, loop, request path, asset or measured slowness |
| Localization (fa) | [localization-fa.md](../../.agents/rules/localization-fa.md) | Persian-only UI, RTL, typography, Persian digits, Jalali dates, Iranian formats, input normalization, Persian search ordering | Any user-visible string, number, date, form or layout |
| Code review | [code-review.md](../../.agents/rules/code-review.md) | Self-review obligation, adversarial questions, the review checklist, verdict vocabulary | Before declaring any change complete |
| Git workflow | [git-workflow.md](../../.agents/rules/git-workflow.md) | Git status discipline, commit hygiene, protecting existing work, destructive-command authorization | Before, during and after any git operation |
| Documentation | [documentation.md](../../.agents/rules/documentation.md) | What must be documented, where it lives, keeping it true | Any new convention, decision, command or changed behaviour |
| Reporting | [reporting.md](../../.agents/rules/reporting.md) | Report format, status vocabulary, evidence rules, prohibited phrases | At the end of every task |

Rules are deliberately small and single-purpose. If a rule starts explaining a procedure, that
procedure belongs in a workflow instead.

## 4. Workflows - what each one does and when to run it

Workflows are **executable procedures**: ordered steps with entry and exit conditions, located in
[`.agents/skills/`](../../.agents/skills/). Run exactly one primary workflow per task and announce it
at the start of the response (`WORKFLOW: <name>`).

| Workflow | Skill file | When to run it | What it produces |
| --- | --- | --- | --- |
| Feature development | [feature-development/SKILL.md](../../.agents/skills/feature-development/SKILL.md) | Building or extending functionality | Implementation + tests + reviews + report |
| Bug fixing | [bug-fix/SKILL.md](../../.agents/skills/bug-fix/SKILL.md) | Anything is broken, failing, flaky or unexpected | Root-cause fix + regression test + related-defect list + report |
| Full system audit | [full-system-audit/SKILL.md](../../.agents/skills/full-system-audit/SKILL.md) | A whole-repository health check is requested | Severity-classified findings + prioritized remediation plan |
| Security audit | [security-audit/SKILL.md](../../.agents/skills/security-audit/SKILL.md) | A dedicated security review is requested, or a security-sensitive area changed | Findings with severity, location, impact, recommended fix |
| Performance audit | [performance-audit/SKILL.md](../../.agents/skills/performance-audit/SKILL.md) | Latency, throughput, page weight or resource usage must be assessed | Measured/structural findings + optimization plan |
| Release verification | [release-verification/SKILL.md](../../.agents/skills/release-verification/SKILL.md) | Before any release, tag or deploy; or "is it ready?" | Verification checklist + GO / GO WITH RISKS / NO-GO verdict |

### Choosing a workflow

```
Something is broken?                    -> bug-fix
New or extended functionality?          -> feature-development
Whole-repository health check?          -> full-system-audit
Security specifically?                  -> security-audit
Speed / weight / resources?             -> performance-audit
Ready to ship?                          -> release-verification
```

Audits are **read-only by default**: they report, they do not silently fix. Fixes requested after an
audit run through `bug-fix` or `feature-development`, so that the change is planned, tested and
reviewed like any other.

### Composing workflows

One workflow may invoke another where it is a genuine sub-step: `feature-development` uses the
`bug-fix` method for failures it discovers; `full-system-audit` folds in `security-audit` and
`performance-audit`; `release-verification` runs `security-audit` at release scope. Invoked
workflows are named in the report so the reasoning is traceable.

## 5. How verification works

Verification is the part of the system that is most often faked by accident. The rules are:

1. **Execute, then report.** No claim without a command and its observed output.
2. **Narrow first, then broad.** Run the smallest relevant check, fix, then re-run the wider suite.
3. **Freeze before you verify.** Verification applies to a specific revision. Any edit afterwards
   voids it, and it must be re-run.
4. **Verify the reproduction, not just the test.** For a bug fix, reproduce the original symptom and
   confirm it is gone.
5. **Unverifiable is not passed.** If the environment lacks a runtime, database or browser, the item
   is listed as unverified and the status cannot be `PASS`.
6. **Integrity is absolute.** Tests are never faked, weakened, skipped or deleted to obtain a green
   result; failing tests are information, not obstacles. See
   [qa.md](../../.agents/rules/qa.md#1-non-negotiable-integrity-rules).
7. **Failures are visible.** Warnings, deprecation notices, skipped tests and flaky behaviour are
   reported, never suppressed.

## 6. How reporting works

Every task ends with a report using the format in
[reporting.md](../../.agents/rules/reporting.md#2-report-format):

```
STATUS / IMPLEMENTED / ROOT CAUSES / TESTS EXECUTED / RESULTS /
SECURITY REVIEW / PERFORMANCE REVIEW / REGRESSION REVIEW / REMAINING RISKS / NEXT ACTION
```

- **STATUS** is one of `PASS`, `PASS WITH RISKS`, `BLOCKED`, `FAIL`. `PASS` requires that nothing in
  scope is unverified; if anything is, the honest status is `PASS WITH RISKS`.
- **TESTS EXECUTED** contains the literal commands, and **RESULTS** contains the literal output.
- **REMAINING RISKS** is never omitted. "None identified" is a valid entry; silence is not.
- Vague claims ("everything should work", "looks good", "probably fixed") are prohibited by the
  reporting rule.

Substantial tasks also persist the report to
[`docs/engineering/reports/`](reports/) using the [report template](templates/report.md). Those files
are the project's memory: a new engineer can read why something was done and what was actually
verified.

## 7. How decisions are recorded

Anything expensive to reverse, cross-cutting, or constraining for future work is recorded as an ADR
using the [ADR template](templates/adr.md), in `docs/engineering/adr/`. The format and triggers are
defined in [architecture.md](../../.agents/rules/architecture.md#6-architectural-decision-making).

Decisions that are **not yet made** are even more important to record. They live in
[project-context.md](project-context.md) as open questions, and they may not be resolved silently by
an implementer. Technology choices - runtime, database engine, hosting, integrations - are reserved
to the owner.

## 8. The validator

```bash
node .agents/scripts/validate-engineering-os.mjs
```

Checks that the operating system is internally consistent and machine-readable:

- Every rule and skill file exists and is non-empty.
- Every skill has valid frontmatter: a `name` matching its directory, a description within limits,
  no duplicate names.
- All relative Markdown links resolve to real files (and anchors resolve to real headings).
- The registries in `AGENTS.md` and this document match what is on disk.
- Rule files are not duplicated or contradicting each other's ownership.
- Premature technology decisions have not been introduced (see section 9).
- `SKILL.md` files stay within the size guidance of the Agent Skills specification.

Run it after adding, renaming or removing any rule, skill or script, and before any release. See
[Extending the system](#9-extending-the-system).

## 9. Extending the system

**Add a rule** when a class of obligation must hold for every future change in an area:

1. Create `.agents/rules/<name>.md` following the structure of an existing rule: Rule ID, applies to,
   canonical owner, see also, then numbered sections and a checklist.
2. Register it in [`AGENTS.md`](../../AGENTS.md#6-rule-registry) and in the table in section 3 above.
3. Make sure its ownership does not overlap an existing rule; if it does, decide which rule owns the
   topic and link instead of copying.
4. Run the validator.

**Add a workflow** when a repeatable, multi-step procedure is needed:

1. Create `.agents/skills/<name>/SKILL.md` with frontmatter (`name` must match the directory,
   lowercase, hyphenated) and a body of ordered, executable steps with entry/exit conditions.
2. Keep it under the size guidance in the Agent Skills specification; move long reference material
   into `references/` inside the skill directory.
3. Register it in [`AGENTS.md`](../../AGENTS.md#7-workflow-registry-skills) and in the table in
   section 4 above.
4. Run the validator.

**Do not duplicate text.** Rules link to each other rather than restating. Duplication is how two
documents end up contradicting each other, and contradiction is the failure mode this operating
system exists to prevent.

**Boundary changes require owner input.** Constraints stated by the product owner (language,
technology, deployment region) may not be changed, loosened or worked around by an agent. Raise the
question instead. The validator warns when a rule file appears to decide something that is reserved
to the owner - see [project-context.md](project-context.md#3-open-decisions-must-be-raised-not-assumed).

## 10. Quick start for a new engineer

1. Read [`AGENTS.md`](../../AGENTS.md) - it is short and it is the constitution.
2. Read this document.
3. Read [project-context.md](project-context.md) to learn what is decided and what is still open.
4. Before your first change, open the rule for the area you are touching.
5. Before you finish, run the matching workflow, then report in the standard format.
6. Never claim something works unless you ran it and saw it work.
