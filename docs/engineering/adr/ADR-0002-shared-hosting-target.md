# ADR-0002: Shared-hosting deployment target

- Status: accepted
- Date: 2026-09-14
- Deciders: product owner (constraint `C-8`), engineering agent (design and consequences)
- Rule reference: [architecture](../../../.agents/rules/architecture.md), [backend](../../../.agents/rules/backend.md)
- Related open decisions: `O-2` (database engine), `O-3` (PHP version), `O-4` (actual host), `O-20` (host capability facts)

## Context

The owner requires that the product be installable on ordinary shared PHP hosting inside Iran
(`C-5`, `C-8`), with PHP used for backend concerns only (`C-9`) and no framework (`C-4`). Shared
hosting typically means: no SSH, no long-running processes, no Redis/Memcached guarantee, no
websockets, no Node at runtime, unpredictable CPU and memory limits, cron available with a coarse
granularity, a single database, a web root with per-directory overrides, and a control panel that
may or may not expose PHP version selection.

This is a hard architectural constraint, not a deployment detail: it constrains every subsystem that
would otherwise assume a worker, a queue, a cache server or a build step.

## Decision

1. The application is a **classic PHP request/response application** served by the web server, with a
   front controller and an application router; it must work with and without URL rewriting
   (`mod_rewrite`). When rewriting is unavailable the front controller is still reachable as
   `/index.php?r=/path`, but generated links assume rewriting: a host without it needs the URL
   mode recorded in the install runbook (and confirmed against the real host, `O-20`).
2. **No runtime dependencies beyond PHP and the database.** Development-time tooling (Node, test
   runners) may exist but must never be required to run the product on the host.
3. **Background work is cron-driven**: a scheduled entry point processes a database-backed job queue
   in bounded batches. Every job is idempotent and safe to run twice.
4. **Caching is filesystem- and database-based**, never assumed to be in-memory. Any cache has a
   documented key, TTL, size bound and invalidation trigger.
5. **Rate limiting and counters** use the database (or files), not an in-memory store.
6. **Uploads and generated assets** live in a directory outside the public web root, or in a public
   directory hardened to prevent execution, with a per-deployment writable path defined in
   configuration.
7. **Configuration** lives in a file outside the web root (or protected), with a documented
   `.env`-style example; secrets never enter the repository.
8. **Sessions** use PHP sessions backed by a configurable store (files by default, database when
   multiple servers or stricter security is needed).
9. The installer/updater is a scripted, re-runnable sequence: check requirements, write
   configuration, run migrations, verify writability, and report what it did. Installation must not
   require shell access.
10. Every feature must state how it behaves when a scheduled job has not run, when the host is slow,
    and when a request is retried.

## Alternatives considered

| Alternative | Why it was rejected |
| --- | --- |
| A VPS with background workers, Redis and a real queue | Contradicts `C-8`. It may still become a future option - this decision is deliberately forward-compatible with it, but the product must not require it |
| Serverless functions for background work | Foreign platform reachability and cost are unresolved (`C-5`, `O-5`) and it adds a runtime the owner has not ratified |
| A framework's queue/cache abstractions | Forbidden by `C-4` and would still need a worker or Redis |
| Building assets at deploy time | Requires tooling on the host; forbidden by `C-8`. Assets ship as plain files |

## Consequences

**Positive**

- The product can be sold and deployed to ordinary Iranian hosts, which matches the market and the
  owner's distribution plan.
- The architecture stays simple and debuggable: no distributed state, no invisible infrastructure.
- The same code runs on a VPS later without changes; it simply gets better workers.

**Negative**

- Throughput is bounded by per-request PHP execution; heavy work must be deferred, batched and
  resumable.
- Cron granularity (often one minute, sometimes less frequently; some hosts limit cron count) bounds
  how quickly asynchronous things happen. SMS dispatch and payment reconciliation must tolerate
  delay and must be visible to the user as "in progress" when relevant.
- Some hosts disable functions, change `post_max_size`, block outgoing requests, or omit extensions.
  Capability must be verified on the real host, not assumed (`O-20`).

**Risks**

- A host with no usable cron, no outgoing network access, or a very old PHP version would block
  parts of the product. This is exactly why `O-20` must be answered before Phase 0 code is written.

## Reversal cost

Low to medium. Moving to a VPS is additive: the same application runs, and the cron worker can be
replaced or supplemented by a long-running worker without changing business logic - provided the job
queue stays in the database and the code never assumes "cron runs every minute" as a correctness
guarantee.

## Verification

- Phase 0: an installer/requirements script runs on the target host and reports PHP version,
  extensions, database engine and version, writability, cron capability, upload limits and outgoing
  HTTP - recorded in `O-20`.
- Phase 0: a cron entry point is proven to run and to process a job queue idempotently, including a
  re-run of the same batch.
- Every phase: the deployment runbook in `docs/` is executed on a clean installation, not only on the
  development copy.
