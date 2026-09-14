---
name: security-audit
description: Systematic security review of authentication, authorization, sessions, permissions, API exposure, input validation, uploads, secrets, database access, frontend exposure, admin functionality, rate limits, logging and sensitive data, with each finding reported with severity, location, vulnerability, impact and recommended fix. Use when a security review is requested or a security-sensitive area is changed.
license: Proprietary. See LICENSE if present; otherwise all rights reserved by the repository owner.
metadata:
  owner: chapino
  kind: workflow
  version: "1.0"
---

# Workflow: Security Audit

Announce at the start: `WORKFLOW: security-audit`.

Governing rule: [security](../../rules/security.md) - this workflow is the execution of that rule's
required review areas. Related rules: [backend](../../rules/backend.md),
[frontend](../../rules/frontend.md), [database](../../rules/database.md),
[git-workflow](../../rules/git-workflow.md), [reporting](../../rules/reporting.md).

> Read-only by default. Fix only what the requester asked you to fix. Report everything else.
> A CRITICAL, actively exploitable finding is reported to the user immediately, in the first lines
> of the response, before any other work continues.

---

## Step 0 - Scope and baseline

- State the scope: which endpoints, modules, roles, data and environments are covered.
- Record the revision (branch, commit hash) and the working-tree state.
- Establish the trust boundaries: what is outside the system, what crosses into it, and where the
  system's authority begins.
- Enumerate the actors: anonymous visitor, authenticated user, elevated user, administrator,
  background job, external callback. Verify the actual set from code, not from documentation.

## Step 1 - Authentication

- Credential storage: is a proven, slow, salted hashing mechanism used, with a per-user salt and
  no reversible storage?
- Login flow: brute-force protection, account enumeration resistance, timing consistency, generic
  error messages, lockout/backoff behaviour.
- Password reset and OTP: token entropy, single use, expiry, binding to the account, no leakage of
  account existence, no token in a URL that gets logged or leaked by referrer.
- Session fixation: is the session identifier regenerated on login and on privilege change?
- Registration: rate limited, uniqueness enforced at the storage layer, no role assignment from
  input.

## Step 2 - Authorization and privilege escalation

- Enumerate every protected operation and confirm each performs an authorization check against the
  **specific resource and actor** - not merely "is a user logged in".
- Check for missing checks on state-changing endpoints, secondary endpoints, export/download
  endpoints, and administrative endpoints.
- Check whether roles, flags, ownership, prices, statuses or limits can be set or influenced by
  input (mass assignment, hidden fields, direct object references).
- Check whether any path lets a lower privilege perform a higher-privilege action through a
  parameter, header, cookie, HTTP method or alternate route.
- Check the default: unknown routes and new endpoints must fail closed.

## Step 3 - Sessions and cookies

- Session identifier: entropy, generation source, rotation, expiry, idle timeout, invalidation on
  logout and on password change.
- Cookies: `HttpOnly`, `Secure`, `SameSite`, `Path`, `Domain`, expiry - verified on a real response
  header where the environment allows, otherwise verified in code with the limitation stated.
- No sensitive data in client-readable storage; no session data in a URL.

## Step 4 - Input validation and injection

For every input: query and route parameters, form fields, JSON bodies, headers, cookies, file names,
uploaded content, and third-party callbacks.

- Injection: all queries parameterized; no concatenation of filters, sorting, table or column names.
- Command/code execution: no shell or `exec`-family call, no dynamic `include`/`require`, no
  `eval`/`unserialize`/deserialization path built from input.
- Path traversal: file paths never derived from input without canonicalization and confinement to a
  known base directory.
- XSS: output encoded for its exact context (HTML text, attribute, URL, JSON, script); no
  `innerHTML` with untrusted data; no unescaped interpolation into templates.
- CSRF: every state-changing request authenticated and protected; GET never mutates.
- SSRF: server-side fetches to user-influenced targets are allowlisted by scheme, host and resolved
  address.
- Content type confusion: request bodies parsed according to a declared, validated media type.

## Step 5 - Uploads and files

- Type verified by real content inspection, not by extension or client-provided MIME type.
- Size bounded; count bounded; storage path not attacker-controlled; original filename never used as
  a path or as the stored name.
- Stored outside the web root, or served with safe headers and no execution rights; verify the
  served response does not execute or inline unexpected content.
- Decompression/processing of untrusted files bounded (zip bombs, image bombs, huge parsers).

## Step 6 - Secrets and configuration exposure

- Scan the repository (including history where feasible) for credentials, keys, tokens, connection
  strings and `.env` files that are committed.
- Verify no secret reaches the client (bundles, HTML, inline scripts, source maps) and none reaches
  the logs or error output.
- Check that production error verbosity is off in production and that stack traces/paths/SQL are not
  returned to clients.
- Check that build/dependency directories, dumps and backups are not committed.
- If a secret is found: report its location, do not print the value, recommend rotation, and note
  that history rewriting requires explicit authorization (see
  [git-workflow](../../rules/git-workflow.md#4-destructive-commands---explicit-authorization-required)).

## Step 7 - Database access and data handling

- Least privilege: does the application user have only the rights it needs?
- Are sensitive fields (personal data, identifiers, tokens) readable by every query path that can
  reach them?
- Are there queries that return more than the caller needs (over-broad payloads, `SELECT *` into a
  response)?
- Personal data: minimized, purpose-bound, retention defined; deletion requests executable in the
  primary store; backups covered by the owner's stated policy.
- Seed/fixture data contains no real personal data and no production credentials.

## Step 8 - Frontend exposure

- No authorization decision trusted from the client; hidden UI is not a control.
- No sensitive data embedded in markup, comments, data attributes, local storage or source maps.
- Client error messages do not disclose internal identifiers, stack traces or existence oracles.
- Third-party scripts, CDN assets and fonts are disclosed and were an explicit owner decision; in
  the Iranian deployment context, verify they are reachable and permissible - do not assume.

## Step 9 - Admin functionality

- Admin routes are separately authorized on every action, not only on the entry page.
- Admin authentication is at least as strong as user authentication; stronger where possible.
- Administrative actions are audited (who, what, when, from where).
- Admin surfaces are not discoverable through public navigation, robots files or predictable paths,
  and are rate limited.

## Step 10 - Rate limiting, abuse and denial of service

- Enumerate endpoints where abuse is profitable or destructive: login, registration, reset, OTP,
  search, upload, unauthenticated writes, exports, expensive aggregations.
- Verify each has a server-side limit with a defined window, threshold, response and monitoring
  signal, keyed on something not trivially rotatable.
- Check for unbounded work: unlimited pagination, unbounded list input, expensive queries triggered
  by a single request, large payload acceptance, long-running synchronous operations.

## Step 11 - Logging and monitoring

- Verify security-relevant events are logged: authentication success/failure, authorization denial,
  privilege change, administrative action, input rejection.
- Verify logs contain no passwords, tokens, session identifiers, card data or full personal records.
- Verify failures are visible: no swallowed security exception, no "failed open" behaviour.

## Step 12 - Reporting

Each finding:

```
ID:             SEC-<nn>
SEVERITY:       CRITICAL | HIGH | MEDIUM | LOW | INFO     (see ../../rules/security.md#3-severity-model)
LOCATION:       file:line, endpoint, or configuration key
VULNERABILITY:  the weakness, named precisely (e.g. IDOR on invoice download)
IMPACT:         what an attacker gains, concretely
REPRODUCTION:   the steps or request sequence, or the reason exploitability is believed
EVIDENCE:       real output or code excerpt
RECOMMENDED FIX: the specific control to add or change
CONFIDENCE:     verified by execution | verified by code inspection | suspected
```

Report structure (saved to `docs/engineering/reports/YYYY-MM-DD-security-audit.md`):

- `STATUS` per the [reporting vocabulary](../../rules/reporting.md#1-status-vocabulary) - any
  CRITICAL finding means the status is at best `FAIL`, and the response leads with it.
- `SCOPE` and `REVISION`
- `METHOD` (what was inspected, which commands ran, what could not be run)
- `FINDINGS` (in the format above, ordered by severity)
- `SEVERITY SUMMARY` (counts)
- `WHAT WAS CHECKED AND FOUND CLEAN` (explicit - absence of findings must be evidence-based)
- `BLIND SPOTS` (areas not assessable in this environment, and what would be needed)
- `RECOMMENDED REMEDIATION ORDER`

Never claim "secure" or "no vulnerabilities". The honest statement is: "these areas were checked in
this way, with these results, and these areas could not be assessed."
