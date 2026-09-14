# Rule: Architecture

**Rule ID:** `architecture`
**Applies to:** every change that touches boundaries, modules, dependencies, contracts, data flow, or that adds a subsystem.
**Canonical owner of:** system boundaries, module layout, dependency direction, API and data contracts, architectural decision records.
**See also:** [`backend`](backend.md), [`frontend`](frontend.md), [`database`](database.md), [`performance`](performance.md).

---

## 1. Inspect before you build

Before any major change:

1. Read the current structure: directories, entry points, module boundaries, configuration.
2. Identify the existing contracts: request/response shapes, storage shapes, shared conventions.
3. Identify the blast radius: which modules, endpoints, pages and data are affected.
4. State what will change and what will deliberately not change.
5. Only then design.

If the repository has no structure yet, say so - do not invent one in passing. A new structure is an
architectural decision and requires an ADR (see section 9).

## 2. The qualities every design is judged on

| Quality | The question to answer |
| --- | --- |
| Boundaries | What is inside this system and what is outside it? Who talks to whom? |
| Separation of concerns | Does each module have one reason to change? |
| Modularity | Can this unit be understood, changed and tested without reading the entire codebase? |
| Coupling / cohesion | Are dependencies explicit and few, and is related behaviour kept together? |
| Scalability | What breaks first if traffic, data volume or user count multiplies by ten? |
| Maintainability | Will a competent engineer who has never seen this understand it in an hour? |
| Extensibility | Can the next obvious feature be added by extending, not rewriting? |
| Testability | Can the behaviour be exercised without a browser, a network, or a production database? |
| Dependency management | Is each new dependency justified, maintained, licence-compatible and pinned? |
| API contracts | Are request/response shapes documented, validated and versioned where public? |
| Database boundaries | Does persistence logic stay behind a single, named layer? |
| Frontend/backend boundary | Does the frontend depend only on the documented contract, never on internals? |
| Backwards compatibility | Who breaks if this changes, and is that acceptable and announced? |

## 3. Rules of construction

1. **Prefer simple, explicit architecture over clever architecture.** Abstraction must be earned by
   at least two real, present use cases. No speculation-driven generality.
2. **No unnecessary design patterns.** A pattern is justified only when it removes a concrete pain
   that exists today. Naming a class `FactoryStrategyAdapter` does not make the design better.
3. **One direction of dependency.** Define the allowed direction between layers and never violate
   it. Cycles between modules are a defect, not a style choice.
4. **Explicit over implicit.** Prefer visible wiring over magic naming, magic file loading, and
   convention-over-configuration tricks that hide control flow.
5. **Boundaries are contracts.** Anything crossing a boundary (request payload, stored row, file,
   queue message) must be validated at the boundary and typed consistently inside.
6. **Fail fast at the edges, be tolerant inside.** Validate and reject early; never propagate
   half-valid data deeper into the system.
7. **Deletion is design.** Removing a module is an architectural change and follows the same rules.
8. **No parallel implementations.** When a second way to do the same thing appears, one of them must
   be removed, not maintained "just in case".

## 4. Backwards compatibility

- A breaking change to a consumed contract requires: an explicit decision, a migration path, an
  announcement in the report, and never a silent swap.
- Prefer additive change (new field, new endpoint, new optional parameter) over mutation of existing
  shapes.
- Removing or renaming anything that another part of the system reads is a breaking change.
- When compatibility cannot be preserved, say so loudly in the report under `REMAINING RISKS`.

## 5. Scalability

- Reason about the multiplier, not the current number: ten times the rows, ten times the users, ten
  times the file size.
- Move work out of the request path only when there is evidence it belongs elsewhere.
- Stateless request handling is the default goal; if state must be shared, name where it lives.
- Any unbounded thing (list, query, response body, upload, log line, JSON document) must have a
  documented limit.

## 6. Architectural decision making

Capture a decision when it is expensive to reverse, affects more than one module, or constrains
future work. Do not capture trivia.

Recording format - `docs/engineering/adr/ADR-<number>-<slug>.md`:

```markdown
# ADR-<number>: <decision title>

- Status: proposed | accepted | superseded by ADR-<n>
- Date: YYYY-MM-DD
- Deciders: <owner / agent>
- Rule reference: architecture

## Context
## Decision
## Alternatives considered
## Consequences (positive, negative, risks)
## Reversal cost
```

An ADR records a decision and its consequences. It never records what has not been decided.
**In this repository, technology choices (language runtime, database engine, hosting, integrations)
are reserved to the product owner and must be raised as questions, not decided unilaterally.**

## 7. Anti-patterns to reject

- God modules or files that everything imports.
- Business logic duplicated between frontend and backend with no single source of truth.
- A "utils" or "helpers" dumping ground with no domain meaning.
- Framework-shaped code in a framework-free codebase.
- Hidden global state and singletons used as an object graph.
- Premature microservices, event buses, queues and caches with no measured need.
- Copy-paste forking of a module to "save time".

## 8. Checklist

- [ ] Structure and contracts inspected before designing.
- [ ] Each layer has one responsibility and a clear interface.
- [ ] Dependency direction is respected; no cycles introduced.
- [ ] Every boundary validates its input.
- [ ] No new abstraction without at least two present use cases.
- [ ] No unbounded collection, query or response.
- [ ] Compatibility impact identified and stated.
- [ ] Design is testable without the full production environment.
- [ ] New dependency justified, pinned, and licence-checked (or explicitly avoided).
- [ ] Decision recorded as an ADR when it is expensive to reverse.
- [ ] Nothing in this change invents a framework, database or domain decision that the owner has not ratified.
