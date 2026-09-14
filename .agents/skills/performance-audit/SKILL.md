---
name: performance-audit
description: Evidence-driven performance review of database query efficiency, N+1 patterns, indexes, API latency, unnecessary requests, frontend rendering, bundle and asset size, lazy loading, caching, memory leaks and unnecessary computation, producing measured findings and a prioritized optimization plan. Use when latency, throughput, resource usage or page weight must be assessed or improved.
license: Proprietary. See LICENSE if present; otherwise all rights reserved by the repository owner.
metadata:
  owner: chapino
  kind: workflow
  version: "1.0"
---

# Workflow: Performance Audit

Announce at the start: `WORKFLOW: performance-audit`.

Governing rule: [performance](../../rules/performance.md). Related:
[database](../../rules/database.md), [backend](../../rules/backend.md),
[frontend](../../rules/frontend.md), [reporting](../../rules/reporting.md).

> **No number is reported that was not measured.** If a measurement cannot be taken in this
> environment, the finding is classified as "structural" (wrong by construction, e.g. an N+1 loop or
> an unbounded query) rather than "measured", and that distinction is stated explicitly.

---

## Step 0 - Define the scenarios and the baseline

Before inspecting anything, define what "slow" means for this product:

- The scenarios that matter (critical user journeys, the heaviest endpoints, the most frequent
  pages).
- The expected data volume at each of them (rows, users, file sizes now and in a year).
- The environment limits: can this sandbox run the application? Is there a database? Is there a
  browser? Network access?

Then capture a baseline where possible: request duration, query count, response size, page weight,
render time. Record the exact method, because the improvement claim must be comparable.

## Step 1 - Database and query efficiency

Inspect every data access path:

- **Query count per operation.** Count it. Query counts that grow with the number of rows are N+1
  defects regardless of current timing.
- **Query plans** for reads on potentially large tables: is an index used, and how many rows are
  examined versus returned?
- **Missing indexes** for filters, joins and sorts; **unused indexes** that only cost writes.
- **Unbounded reads and writes**: no `LIMIT`, no pagination, `SELECT *`, row-by-row loops, unbounded
  `IN` lists.
- **Aggregates** over growing tables (counts, sums, group bys) - cost now and at scale.
- **Transaction duration**: lock hold time, network calls inside transactions, long-running work
  inside a transaction.
- **Write amplification**: indexes, triggers, logging and full-row updates that rewrite more than
  needed.

For each finding, state: the operation, the query (or its shape), the measured or structural cost,
and the fix.

## Step 2 - API and request-path latency

- Identify the dominant contributors per endpoint: compute, storage round trips, external calls,
  serialization, payload size.
- Look for repeated work inside a single request (the same lookup performed many times), unnecessary
  round trips, and chatty designs where one call would do.
- Check payloads: fields returned but never used, oversized responses, missing compression,
  unbounded lists.
- Check caching: what is cached, with which key, TTL, invalidation and size; is anything cached that
  must not be (authorization outcomes, personal data) or cached so aggressively that users see stale
  data?
- Check long work in the request path that could be moved out.
- Check connection/setup overhead per request.

## Step 3 - Frontend rendering and delivery

- **Requests per page**: count them, and count the ones that are unnecessary, duplicated, or
  blocking.
- **Assets**: unused libraries, oversized images, missing dimensions, no lazy loading, uncompressed
  responses, missing caching headers, render-blocking resources, web fonts that delay Persian text
  rendering.
- **Rendering**: layout thrash, forced reflow in loops, full-list re-render on every input, work done
  on scroll, unbounded DOM growth, long tasks blocking interaction.
- **Interaction**: response to user action, debounce/throttle on high-frequency events, cancellation
  of stale requests, absence of request waterfalls caused by sequencing that could be parallel.
- **Persian-specific rendering cost**: font loading strategy and shaping cost for Persian text,
  especially in long lists - state it as a hypothesis unless measured.

## Step 4 - Memory, leaks and resource usage

- Detached DOM nodes and unbounded listeners after re-render or navigation.
- Uncleared timers, intervals, subscriptions and observers.
- Module-level collections that grow with traffic or time; any cache without an eviction policy.
- Handles, streams, connections and locks released on all paths, including errors.
- Unbounded log growth, unbounded in-memory accumulation, unbounded temp files.

## Step 5 - Measurement

When the environment allows, measure rather than reason:

- Server: request timing, query log/perf counters, memory watermark, repeated requests to observe
  variance.
- Database: the plan and row counts for the specific queries.
- Browser: network waterfall, total transfer size, long tasks, layout shift, interaction delay.
- Load behaviour: repeat a representative request enough times to see steady state, not a single
  lucky first call.

Record every command and its real output. Where measurement is impossible, say so.

## Step 6 - Findings and priorities

Finding format:

```
ID:              PERF-<nn>
SEVERITY:        CRITICAL | HIGH | MEDIUM | LOW | INFO      (see ../../rules/security.md#3-severity-model)
AREA:            database | request-path | frontend | memory
LOCATION:        file:line, endpoint, or page
EVIDENCE TYPE:   measured | structural | suspected
MEASUREMENT:     the number, with the method, or "not measurable here"
IMPACT:          user-visible or cost impact at the expected data volume
RECOMMENDATION:  the specific change, and what it should improve
VERIFICATION:    how the improvement will be proven after the fix
```

Priorities (do not micro-optimize out of order):

1. Anything that grows with data volume or breaks a critical journey.
2. Anything adding a round trip, a full scan, or a blocking step to a common path.
3. Payload and asset weight on the most-visited pages.
4. Everything else, folded into related work.

## Step 7 - Output

Produce `docs/engineering/reports/YYYY-MM-DD-performance-audit.md`:

- `STATUS` per the [reporting vocabulary](../../rules/reporting.md#1-status-vocabulary)
- `SCENARIOS AND BASELINE` (numbers, method, environment, or explicit "no baseline available")
- `FINDINGS` (format above, ordered by priority)
- `SEVERITY SUMMARY`
- `PRIORITIZED OPTIMIZATION PLAN` (with the verification method for each item)
- `NOT MEASURED` (what could not be measured and what tooling would be required)
- `CORRECTNESS AND SECURITY CONSTRAINTS` (explicit statement that no check, validation or
  authorization step was proposed for removal)

Then summarize in the response, with the numbers.
