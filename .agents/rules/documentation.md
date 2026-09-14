# Rule: Documentation

**Rule ID:** `documentation`
**Applies to:** every new convention, decision, module, command, configuration, or changed behaviour.
**Canonical owner of:** what must be documented, where it lives, and how it stays true.
**See also:** [`architecture`](architecture.md), [`reporting`](reporting.md), [docs/engineering/README.md](../../docs/engineering/README.md).

---

## 1. Principles

1. **Documentation is part of the change, not a follow-up.** A behaviour change without updated
   documentation is an incomplete change.
2. **Write for the next engineer** who has none of your context: what this is, why it exists, how to
   run it, what will bite them.
3. **One fact, one place.** Link instead of copying. Duplicated documentation rots into contradiction.
4. **Prefer executable truth** (scripts, checks, configuration) over prose that can silently drift;
   prose then explains intent.
5. **No aspirational documentation.** Never document behaviour, commands or features that do not
   exist. Mark future work explicitly as not implemented.
6. **Never document secrets** or real personal data; reference where configuration lives.

## 2. Where each thing lives

| Content | Location |
| --- | --- |
| Agent constitution, registries, operating loop | [`AGENTS.md`](../../AGENTS.md) |
| Always-on rules | [`.agents/rules/`](.) |
| Executable workflows | [`.agents/skills/`](../skills/) |
| Human entry point to the engineering system | [`docs/engineering/README.md`](../../docs/engineering/README.md) |
| Project context, constraint ledger, open decisions | [`docs/engineering/project-context.md`](../../docs/engineering/project-context.md) |
| Architectural decisions | `docs/engineering/adr/ADR-<n>-<slug>.md` |
| Task reports | `docs/engineering/reports/YYYY-MM-DD-<task>.md` |
| Report template | [`docs/engineering/templates/report.md`](../../docs/engineering/templates/report.md) |
| Product/feature documentation | `docs/` once the product exists (subfolders by area) |
| Setup and run instructions | `README.md` plus a `docs/` page for detail |

## 3. What must be documented for a new area

- Purpose and boundary of the module/subsystem.
- How to run it locally, and how to run its checks.
- Configuration it needs, with an example file and no real values.
- Inputs, outputs and failure modes.
- Anything surprising, dangerous or irreversible.
- Open questions and unverified assumptions.

## 4. Keeping it true

- Update documentation in the same change that alters the documented behaviour.
- Delete stale documentation rather than leaving contradictions. If a reader could be misled, the
  text is a defect.
- When documentation and code disagree, code is reality - then fix the document and report it.
- Every command in documentation must be executable as written; never include an untested command as
  if it were verified.

## 5. Checklist

- [ ] Change to behaviour/convention/command is reflected in the right document.
- [ ] No duplicated explanation; links used instead of copies.
- [ ] No secret, credential or real personal data included.
- [ ] No documentation of unimplemented behaviour.
- [ ] Commands in documentation were executed, or marked as unverified.
- [ ] Registry tables (`AGENTS.md`, `docs/engineering/README.md`) updated when rules, skills, scripts
      or conventions were added or renamed.
