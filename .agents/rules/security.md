# Rule: Security

**Rule ID:** `security`
**Applies to:** every change that touches input, identity, permissions, files, money, personal data,
sessions, configuration, dependencies or the admin surface.
**Canonical owner of:** authentication and authorization requirements, input trust boundaries,
attack-surface review, secret handling, session and cookie policy, sensitive-data handling.
**See also:** the [security audit workflow](../skills/security-audit/SKILL.md), [`backend`](backend.md), [`database`](database.md), [`frontend`](frontend.md).

---

## 1. Trust boundary

**Never trust:** user input, query parameters, route parameters, headers, cookies, uploaded files
or their names, client-side state, hidden form fields, localStorage, the referrer, the user agent,
third-party callbacks, queue messages, or data returned by an external service.

**Everything security-relevant must be enforced on the server.** Client-side checks exist for user
experience only; they are never a control. A control that exists only in JavaScript does not exist.

## 2. Required review areas

Every security review covers the applicable rows and states, for each, what was checked and the
result:

| Area | What must be verified |
| --- | --- |
| Authentication | Credential handling, password storage (slow, salted, per-user hashing), brute-force protection, account enumeration resistance, password reset and OTP flows, session fixation on login |
| Authorization | Every operation checks permission against this resource and actor; deny by default; admin separated from user; no client-side role decisions |
| Privilege escalation | Role or ownership values cannot be set by the user; no path from a low privilege to a high one through parameters, headers or state |
| IDOR | Objects are fetched by authenticated ownership or explicit permission, never by a client-supplied identifier alone |
| SQL / query injection | Parameterized queries everywhere; no concatenation for filters, order, table or column names |
| XSS | All output encoded for its HTML context; no unescaped interpolation into markup, attributes, URLs or script; no `innerHTML` with untrusted data; no `eval`-family usage |
| CSRF | Every state-changing request authenticated and protected against cross-site initiation; SameSite plus token strategy stated; idempotent methods never mutate |
| SSRF | Server-side fetches to user-influenced URLs are restricted by allowlist, scheme and resolved-IP policy |
| Command / code injection | No shell, `include` or deserialization path built from input; functions like `exec`, `system`, `popen`, `include $userValue`, `unserialize` are treated as critical findings |
| Path traversal | File names and paths never derived from input without canonicalization and confinement to a known base directory |
| Unsafe uploads | Type verified by content, not extension; size limited; stored outside the web root or served with safe headers; never executed; original filename never used as a path |
| Insecure deserialization | No deserialization of untrusted data; signed or server-generated payloads only |
| Secrets exposure | No credentials, tokens or keys in the repository, client bundles, logs, error output, HTML, or config defaults; `.env` never committed |
| Session security | Server-generated high-entropy identifiers; regenerated on privilege change; invalidated on logout; timeout and idle timeout defined |
| Cookies | Explicit `Secure`, `HttpOnly`, `SameSite`, `Path`, expiry and scope decisions; no sensitive data in a client-readable cookie |
| Rate limiting | Authentication, registration, reset, OTP, search, upload and unauthenticated writes are limited; limits are enforced server-side and observable |
| Sensitive data exposure | Response payloads return only what the caller needs; no internal identifiers, no other users' data, no verbose errors; encoding is UTF-8; caches and logs hold no sensitive values |
| Admin surface | Admin functionality is not reachable by URL guessing for a normal user, is separately authorized on every action, is protected by stronger authentication where possible, and is audited |

## 3. Severity model

| Severity | Meaning |
| --- | --- |
| CRITICAL | Exploitable to compromise accounts, data, money or the server; or already exposed secret. Stop other work. |
| HIGH | Exploitable with limited preconditions; real impact on confidentiality, integrity or availability. |
| MEDIUM | Requires unusual preconditions or has limited impact; weakens defence in depth. |
| LOW | Hardening opportunity, hardening gap, informational weakness. |
| INFO | Observation, not a vulnerability. |

Every finding must include: severity, location (file and line or endpoint), the vulnerability, the
concrete impact, a reproduction path or the reason it is believed exploitable, and a recommended
fix. Findings without a recommended fix are not useful.

## 4. Handling a critical finding

1. Say so immediately in the response, before any other work.
2. Do not begin large unrelated changes until it is understood and either fixed or explicitly
   deferred by the owner.
3. Never paste live secrets into chat, logs or commits - reference their location and recommend
   rotation.
4. If a secret was committed, assume it is compromised: recommend rotation, and state that history
   rewriting is a destructive operation requiring explicit authorization (see [`git-workflow`](git-workflow.md)).

## 5. Implementation rules

- Prefer the platform's built-in, battle-tested mechanisms over hand-rolled cryptography.
- Never invent a crypto scheme. Use the platform's password hashing and random generators.
- Security-relevant randomness comes from a cryptographically secure source.
- Compare secrets and tokens in constant time.
- Deny by default: an endpoint is protected until it is deliberately and explicitly made public.
- Fail closed: an error in a security check denies access.
- Errors must not reveal whether an account, resource or identifier exists to an unauthorized party.
- Every new dependency is a new attack surface; see the [architecture rule](architecture.md#3-rules-of-construction)
  and require an owner decision.
- Keep error reporting verbose in development and minimal in production, and verify which mode the
  environment is in before testing security behaviour.
- Security headers (content type, framing, transport security, referrer policy, CSP where feasible)
  are configured explicitly and verified on a response, not assumed.

## 6. Local law and regional constraints (Iran)

- The deployment target is inside Iran; network egress, third-party services and hosting must be
  chosen by the owner, not assumed.
- Personal data handling, retention and user rights follow the owner's stated policy and applicable
  local requirements; record what is unknown as an open question rather than inventing a policy.
- Never assume a foreign SaaS, CDN or payment provider is reachable or permissible. Ask.

## 7. Checklist

- [ ] Every input at every boundary validated and encoded for its destination context.
- [ ] Every protected operation checks authorization against the specific resource.
- [ ] No identifier trusted from the client where ownership matters (IDOR).
- [ ] Queries parameterized; no injection path in filters, sorting or identifiers.
- [ ] Output encoding verified for HTML, attribute, URL and JSON contexts.
- [ ] CSRF protection on all state-changing operations.
- [ ] Uploads: content-verified, bounded, stored safely, never executed.
- [ ] No secret in code, repo, bundle, log, error or HTML.
- [ ] Session and cookie policy explicit and verified on a real response.
- [ ] Rate limits present on abuse-prone endpoints.
- [ ] Error responses contain no internal detail and no existence oracle.
- [ ] Findings reported with severity, location, impact and recommended fix.
