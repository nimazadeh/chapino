# Engineering Report - Product Constraints, ADRs and Phase Roadmap

- Date: 2026-09-14
- Workflow: this task captured owner decisions and produced the delivery roadmap. It is the
  governance-sequence step that precedes Phase 0 of the roadmap; the
  [documentation rule](../../../.agents/rules/documentation.md),
  [architecture rule](../../../.agents/rules/architecture.md) (ADR obligation) and
  [reporting rule](../../../.agents/rules/reporting.md) were applied.
- Branch: `arena/01a09e2c-chapino`
- Revision verified: the staged tree of the second commit (`docs: ratify product constraints,
  ADRs and phase roadmap`). The commit hash is reported in the task response, because a file cannot
  contain the hash of the commit that introduces it.
- Rules applied: `architecture`, `documentation`, `design-engine` (new), `reporting`, `git-workflow`

---

## STATUS

`PASS WITH RISKS`

Every owner instruction from this task was recorded as a numbered constraint (`C-6` .. `C-15`), the
four architectural consequences were written as accepted ADRs, the ten-phase delivery plan was
written with exit criteria per phase, and the remaining unknowns were turned into twenty numbered
open decisions (`O-1` .. `O-20`) with a questionnaire. The validator passes in strict mode
(9 checks, 0 warnings, 0 errors). The residual risk is inherent to the state: **the plan rests on
owner answers that have not been given yet** (`O-1`, `O-2`, `O-3`, `O-5`, `O-8`, `O-11`, `O-14`,
`O-15`, `O-20`), and no product code exists to verify.

## IMPLEMENTED

**Constraint ledger - `docs/engineering/project-context.md` (rewritten)**

- `C-1` .. `C-5` retained; `C-6` .. `C-15` added from this task's instructions: browser-side
  processing, vanilla-JS studio, shared-hosting target, PHP for backend concerns only, ZarinPal,
  Kaveh Negar, no AI provider now, AI seam required, SaaS + B2B, product shape from the referenced
  demo.
- Open decisions restructured: `O-1` .. `O-20`, each with status, why it matters, and what it blocks.
- Environment facts remain recorded as verified observations, not assumptions.

**Architecture decision records - `docs/engineering/adr/`**

- [ADR-0001](../../../docs/engineering/adr/ADR-0001-browser-side-design-engine.md) - browser-side
  design engine; the design document is the authoritative artefact; the server never renders.
- [ADR-0002](../../../docs/engineering/adr/ADR-0002-shared-hosting-target.md) - shared-hosting
  target; its consequences for routing, jobs, caching, uploads, configuration and installation.
- [ADR-0003](../../../docs/engineering/adr/ADR-0003-deferred-ai-provider-seam.md) - AI generation as a
  disabled, job-shaped, provider-agnostic seam with quotas and a kill switch.
- [ADR-0004](../../../docs/engineering/adr/ADR-0004-iran-region-integrations.md) - ZarinPal and
  Kaveh Negar behind internal interfaces, with server-side verification, idempotent payment state
  machine, cron reconciliation, queued SMS and an explicit OTP policy.
- ADR index updated in `docs/engineering/adr/README.md`.

**Product documents - `docs/product/` (new)**

- `scope.md` - what the product is, the end-to-end user journey from the referenced demo, what is
  inside the first release, what is explicitly outside it, and proposed success criteria.
- `roadmap.md` - the ten phases (0 to 9), each with numbered work items, deliverables, and **exit
  criteria**; the mandatory eight-step execution method for every phase; and a bottleneck table
  mapping each gating decision to the phase it blocks.
- `open-questions.md` - seventeen numbered questions with options, a recommendation, and a
  conservative default where one is safe; a separate list of engineering decisions taken by default
  (library-free client, no Composer, design-document contract, database queue, AI seam, payment
  verification); and the three questions that unblock the most work (`Q-1` host capabilities,
  `Q-5` print contract, `Q-8` revenue model).

**New rule - `.agents/rules/design-engine.md` (registered)**

- Canonical owner of the design-document contract, renderer purity, the coordinate and resolution
  model, the print-export contract, and editor interaction rules - deliberately separate from
  `frontend` so that the most failure-prone part of the product has one explicit owner.
- Registered in `AGENTS.md` (rule registry) and in `docs/engineering/README.md` (rule table and the
  document index, which now also points at the product documents).

**Deliberately not created:** no application code, no directory layout for the application, no
schema, no endpoint, no UI, no dependency, no build tooling. Phase 0 owns all of that, and it is
gated on the host facts in `O-20` / `Q-1`.

## ROOT CAUSES

Not applicable - no defect was fixed in this task.

Two documentation defects were found and fixed while writing:

1. `docs/engineering/README.md` linked `project-context.md#3-open-decisions-must-be-raised-not-assumed`,
   which stopped resolving when the open-decision section became section 4 in the rewritten file.
   Cause: a hand-written anchor, invalidated by a section renumbering; caught by the anchor checker.
2. `design-engine.md` linked the ADR with a relative path one directory level too shallow
   (`../adr/...` from `.agents/rules/` resolves outside the repository). Cause: path-depth error;
   caught by the link checker. Both were caught by the validator **before** commit, which is the
   behaviour the operating system is designed to produce.

## TESTS EXECUTED

```bash
node .agents/scripts/validate-engineering-os.mjs
node .agents/scripts/validate-engineering-os.mjs --strict
python3 - <<'PY'   # independent check: file inventory, code-fence balance, tabs, BOM
PY
git status --porcelain
git diff --cached --stat
```

## RESULTS

- `node .agents/scripts/validate-engineering-os.mjs --strict` ->
  `9 checks passed, 0 warning(s), 0 error(s)` / `RESULT: PASS`.
  Checks: structure (12 required paths), skills (6 files), rules (**14** files), registry
  (14 rules and 6 skills cross-checked against 2 registries), links (276 relative links and anchors
  resolved across 33 documents), contradictions (3 mandatory statements checked for duplication and
  ownership collisions), premature-stack (32 reserved tokens scanned - no unratified technology
  decision appears in the documentation), project-context (**20** open decisions and 5 owner
  constraints recorded), report-template (7 sections and the status vocabulary consistent).
- Intermediate runs of the same validator returned `RESULT: FAIL` twice with the two broken links
  listed under ROOT CAUSES, and two warnings for `O-14`/`O-16` references that did not state they are
  owner decisions. All four were fixed before the final run above.
- Independent Python inventory after the change: 33 markdown documents plus the validator and
  `.gitignore`; every `SKILL.md` frontmatter parsed; zero unbalanced code fences, zero tab
  characters, zero byte-order marks. No source file other than documentation was touched.
- Not executed: anything requiring a runtime. There is no application, and `php` is not installed in
  this environment, so no PHP behaviour was verified - by design, since no product code exists yet.

## SECURITY REVIEW

- **Secrets:** none created, none referenced by value; the new documents require configuration-based
  secrets and state that they are never committed, logged or returned to the client.
- **Security requirements recorded for the coming phases** rather than implemented: server-side
  authority over price/permission/order state; payment treated as successful only after server-side
  verification with an amount match; callbacks treated as hostile input; OTP hashed, single-use,
  expiring, rate limited; SMS and generation queued and idempotent; AI credentials server-side only
  with a kill switch.
- **Attack surface created by this task:** zero - documentation only.
- Not applicable: authentication, authorization, sessions, uploads, injection surfaces (no code).

## PERFORMANCE REVIEW

- Not applicable to the artefact: documentation plus ADRs, all static.
- Performance requirements were **recorded** for later phases: the client render budget in the
  [design engine rule](../../../.agents/rules/design-engine.md#4-performance-budget-client) (no
  full-resolution re-render per pointer move, cancellable export, bounded memory), and the
  shared-hosting constraints in ADR-0002 (bounded cron batches, no in-memory cache assumption).
- No measurement was possible or claimed.

## REGRESSION REVIEW

- Which behaviour could this change affect? The governance layer only. The rewrite of
  `project-context.md` removed nothing that the rules depend on; the rule registry, skill registry,
  report template and status vocabulary were all re-validated and remain consistent.
- Checks re-run: the full validator in strict mode (which verifies registry synchronisation in both
  directions, every relative link and anchor, duplicate rule ids, ownership collisions and report
  consistency), plus the independent Python inventory.
- The first commit's artefacts are untouched except `AGENTS.md` (section 1 rewritten to point at the
  ledger, and one rule added to the registry) and `docs/engineering/README.md` (rule table and
  document index). `git diff` confirms no other file from the previous commit was modified.

## REMAINING RISKS

1. **The plan is unverified against answers that do not exist yet.** Roadmap phases 3 to 9 rest on
   `O-6`, `O-8`, `O-13`, `O-15`, `O-18`, `O-19`. Phase 6 (B2B) in particular is a sketch, because the
   business model behind it has not been chosen - it will be redesigned when `O-15` is answered.
2. **Phase 0 is hard-gated.** Without the host capability facts (`O-20` / `Q-1`), the PHP version,
   database engine, cron availability and outgoing HTTP support cannot be assumed; writing backend
   code before that risks a rewrite, so no code was written.
3. **Print fidelity is unverified.** The entire client-side decision (ADR-0001) depends on the print
   contract (`O-14` / `Q-5`) and on fonts whose licensing is confirmed (`O-16` / `Q-14`). Until a real
   print sample is compared against a studio export, "the export is printable" is an assumption.
4. **Two decisions were taken by the agent and are reversible only at a cost**: the design document
   becoming the authoritative artefact, and the job-queue-plus-cron model. Both are recorded as ADRs
   with reversal costs; if the owner disagrees, this is the moment to say so - not during Phase 4.
5. **"SaaS + B2B on one codebase"** (`C-14`) has an unresolved structural question: whether B2B needs
   per-organization isolation or only per-organization scoping. That choice (`O-15`) changes the
   schema, and choosing it late is expensive.
6. **No automated test exists for any of this**, and none can exist until the application exists.
   The validator covers the governance layer only; it cannot check that a plan is correct.
7. **Regional availability remains unverified for the chosen vendors themselves**: ZarinPal's
   sandbox and Kaveh Negar's API have not been called from the target host. This is verified in
   Phase 4/5, and is listed here so nobody mistakes the ADRs for proven integrations.

## NEXT ACTION

Answer the three gating questions - **Q-1 (host capabilities)**, **Q-5 (print contract)** and
**Q-8 (revenue model)** - plus **Q-2** (whether a host already exists). With those, Phase 0 can be
executed in full: I will write the plan for the phase, build the foundation, run the installer on a
clean environment, and report with evidence before Phase 1 begins.
