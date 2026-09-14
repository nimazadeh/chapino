# chapino

> **Repository status:** engineering governance only. No product code has been written yet and
> no technology decision beyond the owner-stated constraints below has been made.

This repository hosts **chapino**, a production-grade web application for users in Iran with a
Persian-only (fa-IR), right-to-left user interface.

## Owner-stated constraints

These were stated by the product owner and are recorded as constraints, not as design decisions:

- User interface language: Persian (fa-IR) only. RTL-first layout.
- Frontend: vanilla HTML, CSS and JavaScript. No frontend framework.
- Backend: pure PHP. No backend framework.
- Intended deployment and audience: inside Iran.

The product domain, database engine, hosting model, integration providers and feature scope are
**not decided yet** and must not be assumed. See
[docs/engineering/project-context.md](docs/engineering/project-context.md).

## How this repository is governed

All development in this repository is performed under the engineering operating system described in
[AGENTS.md](AGENTS.md) and documented for humans in
[docs/engineering/README.md](docs/engineering/README.md).

Before contributing or asking an agent to change anything, read
[AGENTS.md](AGENTS.md). It defines the mandatory operating loop, the rule registry, the workflow
(skill) registry, the evidence standard, and the reporting format.

## Validate the engineering operating system

```bash
node .agents/scripts/validate-engineering-os.mjs
```

This checks the rule and skill files for structural integrity, broken links, duplicated or
contradictory ownership, and accidental premature technology decisions.
