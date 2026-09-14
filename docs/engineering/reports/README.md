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
| 2026-09-14 | [Phase 0, slice 1: application core](2026-09-14-phase-0-slice-1-core.md) | PASS WITH RISKS | Application core, Persian validation, tests, install self-check |
| 2026-09-14 | [Phase 0, slice 2: data layer, migrations, installer](2026-09-14-phase-0-slice-2-database.md) | PASS WITH RISKS | PDO data layer (sqlite/mysql), schema builder, versioned migrations, installer, environment report |
| 2026-09-14 | [Phase 0, slice 3: sessions, CSRF, rate limiting, job queue](2026-09-14-phase-0-slice-3-security-and-jobs.md) | PASS WITH RISKS | Session and cookie policy, CSRF, database rate limiting, DB-backed job queue with cron entry point, operator tools |
| 2026-09-14 | [Phase 0, slice 4: design system and the page layer](2026-09-14-phase-0-slice-4-design-system.md) | PASS WITH RISKS | RTL design tokens, self-hosted Persian font, base script, base components, server-side view layer, home page, development-only style guide |
| 2026-09-14 | [Phase 0, slice 5: default settings and the host capability report](2026-09-14-phase-0-slice-5-seed-and-capability-report.md) | PASS WITH RISKS | Create-only settings seeder with an owner-input marker, capability report with required/optional/fact lines, cron heartbeat |
| 2026-09-14 | [Phase 0, slice 6: the browser test harness](2026-09-14-phase-0-slice-6-browser-harness.md) | PASS WITH RISKS | Shared cases and fixtures executed in jsdom and behind a development-only page for a real browser; base-script defects found and fixed |
