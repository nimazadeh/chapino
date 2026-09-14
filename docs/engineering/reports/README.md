# Task Reports

The durable record of what was done, what was verified, and what remains risky. Reports are how a
new engineer learns why the code looks the way it does - and how an unverified claim is prevented
from being mistaken for a verified one.

## Naming

```
YYYY-MM-DD-<task-slug>.md                  # features, refactors, chores
YYYY-MM-DD-bug-<slug>.md                   # defect fixes
YYYY-MM-DD-full-system-audit.md            # whole-repository audit
YYYY-MM-DD-security-audit.md               # security audit
YYYY-MM-DD-performance-audit.md            # performance audit
YYYY-MM-DD-release-verification-<version>.md
```

Slug rules: lowercase, hyphen-separated, no spaces, no dates inside the slug.

## Format

Every report follows [.agents/rules/reporting.md](../../../.agents/rules/reporting.md) and is created
from [../templates/report.md](../templates/report.md).

## Rules

- Reports are **written after** the work, from real command output - never before, never from memory.
- A report is immutable once committed. If a claim turns out to be wrong, add a correction inside the
  report (a `CORRECTION` section) rather than editing history silently.
- `STATUS: PASS` requires that nothing in scope was left unverified.
- Never leave the `REMAINING RISKS` section empty; write "none identified" if that is the truth.
- Audit reports are read-only records: fixes found by an audit are performed through the
  `bug-fix`/`feature-development` workflows and reported separately.

## Index

| Date | Report | Status | Scope |
| --- | --- | --- | --- |
| 2026-09-14 | [Engineering operating system bootstrap](2026-09-14-engineering-os-bootstrap.md) | PASS WITH RISKS | Governance layer bootstrap (no product code) |
| 2026-09-14 | [Product constraints, ADRs and phase roadmap](2026-09-14-product-constraints-and-roadmap.md) | PASS WITH RISKS | Owner decisions recorded; roadmap defined (no product code) |
