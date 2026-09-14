# Rule: Performance

**Rule ID:** `performance`
**Applies to:** any query, loop, request path, job, render path, asset or measured slowness.
**Canonical owner of:** performance measurement discipline, database and API performance review,
frontend delivery and rendering performance, resource-footprint review.
**See also:** the [performance audit workflow](../skills/performance-audit/SKILL.md), [`database`](database.md), [`backend`](backend.md), [`frontend`](frontend.md).

---

## 1. Evidence first

1. **Measure before optimizing.** Establish a baseline: the exact scenario, the data volume, the
   environment and the observed numbers (duration, query count, payload size, memory).
2. **Optimize the dominant cost, not the visible one.** Find where the time is actually spent;
   intuition about performance is usually wrong.
3. **Prove the improvement.** Re-measure the same scenario with the same method and report both
   numbers. An unmeasured optimization is a change, not an improvement.
4. **No arbitrary micro-optimizations.** Reordering cheap operations for imaginary gains, shortening
   names, or hand-inlining code are not performance work.
5. **Never trade correctness or security for speed.** Removing a check, a validation or an
   authorization step to save milliseconds is prohibited.
6. If no measurement tooling exists yet, say so: state the risk, propose the measurement, and do not
   claim an improvement.

## 2. Database and query performance

- Query count per request is a first-class metric. Watch for a count that grows with the number of
  rows (N+1) - that is a defect regardless of current timing.
- Check the plan for anything touching a growing table: is it using an index, and how many rows does
  it examine?
- `SELECT *`, unbounded result sets, missing `LIMIT`, and sorting without a supporting index are
  defects.
- Aggregations, counts and joins over large tables must be reviewed for cost or precomputed.
- Batched writes instead of row-by-row loops; bulk reads instead of per-item lookups.
- Missing or redundant indexes both cost: verify each index is used by a real query, and that the
  filters/joins/sorts it serves are covered in a useful order.
- Transactions held open across network calls or user interaction extend lock duration; keep them
  short.

## 3. API and request-path performance

- Latency budget per endpoint, stated, with the dominant contributors identified.
- Remove unnecessary round trips; avoid chatty designs where one call would do.
- Cache deliberately: define key, TTL, invalidation, size and stampede behaviour; never cache
  authorization outcomes without a documented invalidation strategy.
- Move genuinely long work out of the request path (background processing), and never make the user
  wait for work they do not need to see.
- Compress and bound responses; paginate every collection with a documented maximum page size.
- Connection reuse instead of per-request setup where the environment supports it.
- Beware of per-request re-computation of the same expensive value within one request; memoize within
  the request scope only.

## 4. Frontend performance

- **Bundle and asset size**: no unused library, no duplicate asset, no full library for one function.
  Report the before/after size of what you change.
- **Delivery**: correct caching headers, compression, modern formats for images, correct dimensions,
  lazy loading below the fold, non-blocking scripts, critical CSS first.
- **Rendering**: avoid layout thrash (reads and writes interleaved), avoid unbounded DOM growth,
  virtualize or paginate long lists, do not block the main thread with synchronous work.
- **Network**: minimize requests, cancel stale requests, debounce high-frequency input, avoid
  polling where an event or a longer interval suffices.
- **Interaction**: visible response to user action within a perceptible threshold; long operations
  show progress and remain cancellable.
- **Measure with real tooling** where available (browser performance panel, request waterfall,
  Lighthouse-type audit). If unavailable, say so rather than guessing.

## 5. Memory, leaks and resource usage

- Detached DOM nodes, unbounded listeners, uncleared timers/intervals, growing module-level caches,
  and unclosed handles are leak candidates - check them explicitly in long-lived pages and services.
- Any in-memory collection that grows with traffic or time must have a bound and an eviction policy.
- File handles, database connections, streams and locks must be released on every path, including
  the error path.

## 6. Reporting performance work

Report as: scenario, baseline number, change made, result number, method used, and what remains
unverified. Never say "much faster" without numbers, and never present a number you did not measure.

## 7. Checklist

- [ ] Baseline measured (or explicitly stated as unavailable).
- [ ] Dominant cost identified by measurement, not intuition.
- [ ] Query count and plans reviewed; no N+1; no unbounded read or write.
- [ ] Indexes match the real access patterns.
- [ ] Caching (if any) has key, TTL, invalidation and bound defined.
- [ ] Assets: no unused dependency; images sized, compressed and lazy where appropriate.
- [ ] No main-thread blocking, no unbounded DOM growth, no stale request races.
- [ ] Leak candidates checked (timers, listeners, handles, caches).
- [ ] Before/after numbers reported, or an honest statement that measurement was not possible.
- [ ] No correctness or security control was relaxed for speed.
