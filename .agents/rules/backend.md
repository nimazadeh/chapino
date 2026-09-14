# Rule: Backend Engineering

**Rule ID:** `backend`
**Applies to:** every server-side change - endpoint, business rule, validation, authentication, job,
cache, log line, or storage call.
**Canonical owner of:** server-side request handling, business logic, server-side validation, error
handling and logging, background processing, rate limiting.
**See also:** [`security`](security.md), [`database`](database.md), [`architecture`](architecture.md), [`performance`](performance.md).

---

## 1. Design rules

1. **The server is the only authority.** Client input is hostile until validated server-side.
2. **One responsibility per unit.** A handler validates, authorizes and delegates. Business rules
   live in a named service, not in a route closure and not in a template.
3. **Thin transport, thick domain.** Request/response plumbing must not contain business decisions.
4. **Explicit input contracts.** Every endpoint declares which fields it reads, their types, their
   required/optional status, and their bounds (length, range, format, allowed values). Unknown or
   unexpected fields are rejected or ignored deliberately - never silently passed through.
5. **Typed outputs, single shape.** One resource has one response shape. No field that sometimes
   appears and sometimes does not, unless the contract documents it.
6. **Idempotency where retries are possible.** Any operation a client may retry (payments, submits,
   webhooks, uploads) must state its idempotency strategy.
7. **Fail closed.** If an authorization, validation or integrity check errors out, the request is
   denied, not allowed.

## 2. Business logic

- Lives in exactly one place. Never duplicated across endpoints, jobs and templates.
- Does not read `$_GET`, `$_POST` or headers directly; it receives validated values.
- Does not know about HTTP status codes, HTML or template syntax.
- Handles boundaries explicitly: zero, one, many; empty; maximum; negative; duplicate.
- Handles time explicitly: server timezone vs. user timezone, DST, date boundaries.

## 3. Validation

Validate on the server, always, in this order:

1. **Presence and shape** - required fields exist, array vs. scalar is correct.
2. **Type and format** - integer, decimal, string, date, identifier, enum membership.
3. **Bounds** - minimum, maximum, length, precision, allowed count of items.
4. **Semantic validity** - does this make sense in context (dates in order, quantity available,
   relation exists and belongs to this actor).
5. **Authorization** - may this actor perform this operation on this specific object (not merely
   "is logged in").

Prohibited: trusting hidden fields, trusting a client-sent price/role/owner/status, reading a value
the user could not legitimately set, validating only in JavaScript.

## 4. Authentication, authorization, sessions

- Authentication establishes identity; authorization decides permission. They are separate checks
  and both must exist on every protected operation.
- Every access-control decision is made server-side, on every request, against the resource's owner
  or the actor's permission - never against a client-supplied identifier.
- Deny by default. A new endpoint is private until proven otherwise.
- Session identifiers: regenerated on privilege change, invalidated on logout, expiring, marked
  secure against transport and script access. Details and cookie policy live in
  [`security`](security.md).
- Authorization failures return a generic denial. Never leak whether a resource exists to an
  unauthorized actor.

## 5. Middleware and cross-cutting concerns

Middleware is for concerns that apply to many requests: authentication, session loading,
rate limiting, request identifiers, CSRF verification, output headers, error trapping,
logging/tracing. Middleware must:

- be ordered explicitly and documented,
- be idempotent and safe to run on unauthenticated requests unless documented otherwise,
- never contain business rules,
- never silently swallow an exception.

## 6. Database interaction

- All persistence goes through one named access layer; ad-hoc queries in handlers are a defect.
- Parameterized queries only. String-concatenated SQL is forbidden, including in "safe" filters,
  ordering, and `IN` lists.
- One logical operation = one transaction boundary. Partial writes are defects.
- Transactions must be short, must define their isolation expectations, and must define lock
  ordering to avoid deadlocks.
- Bulk operations must be batched; unbounded `IN (...)` lists and row-by-row loops are defects.
- See [`database`](database.md) for schema, index and migration obligations.

## 7. Transactions and concurrency

For every write path, answer:

- What happens if two identical requests arrive at the same moment?
- What happens if the client retries?
- What happens if the process dies after the write starts?
- Can a read observe a half-applied change?

Rules: guard check-then-act sequences with a transaction, a unique constraint or an atomic
conditional update; never rely on application timing; prefer the database as the arbiter of
uniqueness.

## 8. Queues, jobs, events

- Every job is **idempotent** and safe to re-run; a job that double-charges, double-sends or
  double-creates is a defect.
- Every job defines: payload contract, retry policy, timeout, failure visibility, and whether it is
  safe to lose.
- Jobs validate their input like any other boundary; a queue message is untrusted input.
- Long work does not run inline in a request.
- Failures are visible: dead-letter or explicit failure state, never a swallowed exception.

## 9. Caching

- Every cache entry declares: key construction, TTL, invalidation trigger, and maximum size.
- Cache keys are namespaced and include every input that affects the value.
- **Cache invalidation is part of the feature, not an afterthought.** Any write that changes a cached
  value must invalidate or update it in the same change.
- Never cache an authorization decision without an explicit, documented invalidation strategy.
- Never cache personal or sensitive data in a shared store without an explicit owner decision.
- Caches degrade gracefully: a cache failure must not become an application failure.

## 10. Error handling

- One strategy per boundary: translate internal errors into a documented, stable external error
  shape at the edge.
- Never expose stack traces, file paths, SQL, framework versions or internal identifiers to clients.
- Never swallow an exception silently. Catch only what you can handle; otherwise let it propagate to
  the central handler.
- Every error path either recovers, retries, or fails loudly with context.
- Distinguish client errors (validation, permission, not found) from server errors (bug, dependency
  failure). Never return success with an error inside the body.
- Log the correlation identifier with every error; see below.

## 11. Logging and observability

Log: request start/end with a correlation identifier, the operation, the outcome, duration, and the
identity of the actor (never the secret). Log all failures at their boundary.

Never log: passwords, tokens, session identifiers, card data, national identifiers, full personal
records, or anything that would be a security incident if pasted into a chat.

Logging must be structured and machine-readable where the environment allows; free-text noise is a
defect. Log levels must be meaningful: `error` means a human may need to act.

## 12. Rate limiting

Identify and protect every endpoint where abuse is profitable or destructive: authentication,
registration, password reset, OTP, search, upload, and any write available to unauthenticated
users. Rate limits are keyed on a value the attacker cannot trivially rotate in isolation, and must
have a defined window, threshold, response, and monitoring signal.

## 13. Mandatory defect hunt

While working in backend code, actively search for and report:

| Class | What to look for |
| --- | --- |
| N+1 queries | A query inside a loop or per-row accessor; per-item checks against storage |
| Race conditions | check-then-act on counters, balances, stock, uniqueness, "is it already done" |
| Authorization bugs | Missing ownership check, permission checked in the client, role check only on the route |
| IDOR | A resource fetched by a client-supplied identifier without verifying the actor owns/needs it |
| Mass assignment | Building a record from the whole input including fields the actor must not set |
| SQL injection | Concatenated SQL, unparameterized filters, sorting or table names from input |
| Inconsistent transactions | Multi-step write without a transaction, or commit before external side effects |
| Duplicated business logic | The same rule implemented in more than one place, drifting apart |
| Inefficient queries | `SELECT *`, missing `LIMIT`, non-selective filters, full scans, unnecessary joins |
| Missing indexes | Filters, joins and sorts on unindexed columns on data that will grow |
| Improper error handling | Swallowed exceptions, leaked internals, success-shaped failures |
| Unbounded work | Unlimited pagination, unbounded file read, unbounded job input |

Anything found must be reported even when it is out of scope for the current task. Report it; do not
silently expand the change.

## 14. Checklist

- [ ] Input validated server-side for shape, type, bounds and semantics.
- [ ] Authorization checked against the specific resource, not just the route.
- [ ] Every write: transaction boundary, concurrency behaviour and idempotency stated.
- [ ] Queries parameterized, bounded and backed by appropriate indexes.
- [ ] Response shape documented, consistent, and free of leaked internals.
- [ ] Errors mapped to a stable external shape; nothing sensitive exposed.
- [ ] Logging: correlation id present, secrets absent.
- [ ] Caching: key, TTL, invalidation and size defined.
- [ ] Rate limiting considered for abuse-prone endpoints.
- [ ] Defect hunt table (section 13) applied and findings reported.
- [ ] A test exists that exercises the invalid, unauthorized and empty paths, not just the happy path.
