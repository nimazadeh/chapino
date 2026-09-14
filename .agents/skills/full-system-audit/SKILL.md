---
name: full-system-audit
description: Read-only whole-repository inspection covering architecture, backend, frontend, database, security, performance, testing, UX, accessibility, dependencies, configuration, git hygiene, build system and deployment readiness, producing severity-classified findings and a prioritized remediation plan without fixing anything unless explicitly asked. Use when a full health check, technical audit or repository review is requested.
license: Proprietary. See LICENSE if present; otherwise all rights reserved by the repository owner.
metadata:
  owner: chapino
  kind: workflow
  version: "1.0"
---

# Workflow: Full System Audit

Announce at the start: `WORKFLOW: full-system-audit`.

Governing rules: [architecture](../../rules/architecture.md), [backend](../../rules/backend.md),
[frontend](../../rules/frontend.md), [database](../../rules/database.md), [qa](../../rules/qa.md),
[security](../../rules/security.md), [performance](../../rules/performance.md),
[localization](../../rules/localization-fa.md), [code-review](../../rules/code-review.md),
[git-workflow](../../rules/git-workflow.md), [documentation](../../rules/documentation.md).

> **This workflow is read-only by default.**
> Do not fix findings during the audit unless the requester explicitly asked for fixes. The
> deliverable is an accurate picture and a prioritized plan - not a large unverified diff.
> Exception: a CRITICAL security finding that is actively exploitable is reported immediately, and
> only fixed on request.

---

## Step 0 - Establish audit scope and baseline

- State what is in scope and what is not (which directories, which layers, which environments).
- Record the revision: branch, commit hash, working-tree cleanliness (`git status`).
- Record what the project claims to be. If documentation and code disagree, that is Finding #1 of
  type `documentation`.
- Note the environment limits: which checks can actually be executed here, and which cannot (no
  runtime, no database, no browser, no network). These become declared blind spots - never guessed
  results.

## Step 1 - Architecture

Inspect: boundaries, layering, dependency direction, circular dependencies, module size, shared
state, duplicated logic across layers, contract definitions, configuration handling, entry points.

Look for: god files, hidden coupling, business rules duplicated in frontend and backend, framework
assumptions in a framework-free codebase, speculative abstraction, missing/ignored contracts,
no single source of truth.

## Step 2 - Backend

Inspect: routing, request parsing, validation, services, storage access, transactions, error
handling, logging, caching, jobs, rate limiting, idempotency.

Apply the [backend defect hunt table](../../rules/backend.md#13-mandatory-defect-hunt) as a search
list: N+1, race conditions, authorization bugs, IDOR, mass assignment, injection, inconsistent
transactions, duplicated business logic, inefficient queries, missing indexes, improper error
handling, unbounded work.

## Step 3 - Frontend

Inspect: markup structure, module boundaries, client state, API client, forms, rendering, and every
UI state (success, error, loading, empty, invalid, unauthorized, forbidden, not found, server error,
slow network). Check for `innerHTML` with untrusted data, hardcoded hosts, missing timeouts, silent
failures, duplicate templating mechanisms, layout breakage at small widths and in RTL.

Apply the [frontend rule](../../rules/frontend.md#1-the-prime-directive) state table to each screen.

## Step 4 - Database

Inspect: schema, keys, relationships, foreign keys, unique constraints, nullability, defaults,
cascades, indexes, query patterns, migration files and their safety, orphan-row risk, exact types for
money and quantities, timestamp conventions, data-retention reality versus stated policy.

Apply the [migration safety checklist](../../rules/database.md#5-migrations) to every migration that
has not yet been applied to a shared environment, and check whether applied migrations were edited
afterwards (a defect).

## Step 5 - Security

Run the [security audit workflow](../security-audit/SKILL.md) and fold its findings into this report
(do not duplicate the analysis; reference the section).

## Step 6 - Performance

Run the [performance audit workflow](../performance-audit/SKILL.md) and fold its findings in.

## Step 7 - Testing

Inspect: which levels exist (unit, integration, API, database, component, end-to-end, regression,
build), what the critical paths are, which have no test, whether tests are meaningful (assertions
assert something), whether tests are deterministic, whether any test is skipped or disabled, whether
the runner is wired and documented, and whether the reported "green" is reproducible from a clean
checkout.

Uncovered critical paths and authorization boundaries are the highest-value findings in this section.

## Step 8 - UX and accessibility

Inspect: form flows, error recovery, destructive-action confirmation, empty/loading states, keyboard
operability, focus visibility, labels and alt text, contrast, reduced motion, zoom and small-width
usability, touch target size, Persian phrasing quality, and whether the product's states are
consistent with each other.

## Step 9 - Dependencies

Inspect: what is installed versus what is used, unused dependencies, abandoned or unmaintained
packages, pinned versus floating versions, licence obligations, dependency provenance, and whether
any dependency was added without an explicit owner decision. Every external dependency is also an
attack surface and a regional-availability risk (see the
[localization rule](../../rules/localization-fa.md) and the
[security rule's regional section](../../rules/security.md#6-local-law-and-regional-constraints-iran)).

## Step 10 - Configuration

Inspect: how configuration is loaded, defaults, missing-value behaviour, secret handling (no secrets
in the repo, no secrets in the client, no secrets in logs), environment distinction
(development/production) and whether any production-dangerous default exists (debug output on,
verbose errors on, permissive CORS, disabled checks).

## Step 11 - Git hygiene

Inspect: branch state, commit message quality, committed artifacts (build output, dependency
directories, editor noise, large binaries), committed secrets or personal data, `.gitignore`
completeness, history rewrites visible in the log, and untracked files indicating missing ignore
rules.

## Step 12 - Build system and deployment readiness

Inspect: whether the project can be installed and started from a clean checkout using only
documented steps; whether the documented commands actually work; environment variable documentation;
whether there is a reproducible build; whether production configuration is distinguishable from
development; and whether anything in the deployment path is undocumented or manual. Verify by
executing the documented commands where the environment permits, and report exactly which were
executed and which could not be.

---

## Severity classification

Use the same five-level vocabulary as the [security rule](../../rules/security.md#3-severity-model):

| Severity | Meaning |
| --- | --- |
| CRITICAL | Breaks the system, loses or corrupts data, exposes users, or blocks all further work |
| HIGH | Real production impact; will cause incidents or security/data-integrity problems |
| MEDIUM | Meaningful weakness; increases risk, cost or defect rate; should be scheduled |
| LOW | Hardening, consistency, maintainability; fix when touching the area |
| INFO | Observation, context, or a question for the owner - not a defect |

## Finding format

Every finding must include all of these; a finding without location or recommendation is not usable:

```
ID:            AUD-<nn>
SEVERITY:      CRITICAL | HIGH | MEDIUM | LOW | INFO
AREA:          architecture | backend | frontend | database | security | performance |
               testing | ux | accessibility | dependencies | configuration | git |
               build | deployment | documentation
LOCATION:      file:line, endpoint, or command
EVIDENCE:      what was observed (real output or code excerpt)
IMPACT:        what happens because of this
CONFIDENCE:    verified by execution | verified by code inspection | suspected
RECOMMENDATION: the specific change that removes the risk
EFFORT:        small | medium | large
DEPENDENCY:    any finding that must be fixed first
```

## Remediation plan

Group findings into a prioritized plan:

1. **Now (before any new feature)** - all CRITICAL, plus HIGH items that are cheap and blocking.
2. **Next** - HIGH items and security/data-integrity findings.
3. **Scheduled** - MEDIUM items, grouped into coherent work packages.
4. **Opportunistic** - LOW and INFO, folded into related work.
5. **Questions for the owner** - anything requiring a decision rather than engineering.

State explicitly what could **not** be assessed and why (missing environment, missing credentials,
missing data), so the reader knows the boundary of the audit's confidence.

## Output

Produce an audit report at `docs/engineering/reports/YYYY-MM-DD-full-system-audit.md` containing:

- `AUDIT SCOPE` (in scope, out of scope, revision, environment limits)
- `METHOD` (what was inspected, which commands were executed and their real results)
- `FINDINGS` (grouped by area, each in the finding format)
- `SEVERITY SUMMARY` (counts per severity and area)
- `PRIORITIZED REMEDIATION PLAN`
- `BLIND SPOTS` (what was not checked and why)
- `STATUS` using the [reporting vocabulary](../../rules/reporting.md#1-status-vocabulary)

Then summarize in the response. Remember: an audit that fixes things silently is no longer an audit.
