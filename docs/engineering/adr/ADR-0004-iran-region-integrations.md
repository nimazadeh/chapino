# ADR-0004: ZarinPal for payments and Kaveh Negar for SMS, verified server-side

- Status: accepted
- Date: 2026-09-14
- Deciders: product owner (constraints `C-5`, `C-10`, `C-11`), engineering agent (design and consequences)
- Rule reference: [security](../../../.agents/rules/security.md), [backend](../../../.agents/rules/backend.md)
- Related open decisions: `O-6` (authentication method), `O-8` (money rules), `O-19` (invoicing)

## Context

The product sells physical, printed goods and must take payments online inside Iran (`C-5`). The
owner has chosen **ZarinPal** as the payment gateway (`C-10`) and **Kaveh Negar** as the SMS panel
(`C-11`), presumably for one-time passwords and order notifications. Both are Iranian services
reachable from Iranian hosting, which is why no foreign alternative is considered.

Both integrations share a property that makes them dangerous: they are **external, unreliable,
financially consequential, and reachable over plain HTTP from our server**, and they are driven by
data that ultimately came from a user (an amount, a mobile number, a callback). Everything they
return is untrusted input.

ZarinPal's canonical flow is a redirect-based one: the server requests an authority/token for an
amount, redirects the user to the gateway, the gateway returns the user to our callback with a
status and the authority, and the server **must then verify the payment with the gateway's
verification call** before treating it as paid. A callback that is merely received is not a payment.

Kaveh Negar is used for one-time passwords and notifications: a REST call, per-message cost, and
strict regulatory rules in Iran about sending SMS to numbers that did not request it.

## Decision

1. **Both integrations are wrapped behind internal interfaces** (`PaymentGateway`, `SmsProvider`)
   with a single implementation each. No business logic may call the vendor API directly, and no
   vendor vocabulary may leak into the domain model.
2. **The server is the only authority.**
   - The client never sends a price, a currency, an amount or a payment status. The server recomputes
     the payable amount from its own stored data at payment time.
   - A payment is considered successful **only** after the server has verified it with the gateway
     and the verified amount matches the server's stored amount.
   - An SMS code is verified **only** against a server-stored, hashed, single-use, expiring value
     bound to a mobile number and a purpose. The client never receives the code.
3. **Every payment attempt is an idempotent, persisted transaction record** with a unique server-side
   reference, a state machine (`initiated -> pending -> verified | failed | cancelled | refunded`),
   and the gateway reference stored. Verification must be safe to repeat: a repeated callback or a
   repeated verification must never produce a second order or a second credit.
4. **The callback endpoint is treated as hostile input**: it is rate limited, validated, must not
   trust any parameter as proof of payment, must not leak whether an order exists to an unauthorized
   caller, and must be idempotent under replay. Whether it is answered by `GET` or `POST` is
   determined from ZarinPal's current documentation at implementation time (see `O-8`/`O-19`
   answers) and recorded in the integration document.
5. **Reconciliation runs on cron**: a scheduled job re-queries any payment left in `pending` beyond a
   threshold and settles it, so a lost callback cannot leave a paid order unpaid. This is required by
   [ADR-0002](ADR-0002-shared-hosting-target.md) - there is no reliable real-time worker.
6. **SMS is queued, not sent inline.** Every message is a persisted job with a template identity, a
   purpose, a recipient, a status and a retry count. A provider outage must not fail the user's
   request; it must be visible in the queue and retryable.
7. **One-time-password policy is explicit**: length, expiry, single use, hashing at rest, per-mobile
   and per-IP rate limits, attempt limits, resend cooldowns, and no disclosure of whether a number is
   registered (unless `O-6` explicitly decides otherwise).
8. **Secrets live in configuration outside the web root** and are never logged, never returned to the
   client, never committed. Both APIs are called with server-side credentials only.
9. **All outgoing calls are bounded**: connect and read timeouts, bounded retries with backoff for
   idempotent operations only, and a recorded outcome for every attempt. No call may hang a user
   request; long or slow operations are moved to the queue.
10. **Region honesty**: no CDN, font, script, analytics or image may be loaded from a foreign service
    as a side effect of these integrations (`C-5`). Any asset the product needs must be self-hosted.

## Alternatives considered

| Alternative | Why it was rejected |
| --- | --- |
| Calling the gateway or SMS panel directly from controllers | Duplicates retry, timeout, logging and error mapping logic at every call site and leaks vendor shapes into the domain |
| Trusting the callback parameters as proof of payment | Classic payment vulnerability: a forged callback would create a paid order |
| Sending SMS synchronously inside the request | A slow or failing provider would break login and order flows, and shared hosting has no request budget for it |
| Storing OTPs in plaintext or as reversible values | Unnecessary exposure; hashing is free and sufficient |
| Using a foreign payment or SMS provider | Contradicts `C-5` and the owner's explicit choice in `C-10`/`C-11` |

## Consequences

**Positive**

- Payment correctness does not depend on the client, the callback, or timing - only on server-side
  verification against stored amounts plus cron-driven reconciliation.
- Vendor replacement is a single adapter change; vendor outages degrade one queue instead of the
  product.
- The OTP flow is defensible against enumeration, brute force and SMS-cost abuse from day one.

**Negative**

- More persistence than the happy path strictly requires (payment attempts, SMS jobs, OTP records),
  which is the price of idempotency and auditability.
- Reconciliation delay is bounded by the host's cron granularity; a user may briefly see "in
  progress" after paying. The UI must be written to make that acceptable rather than contradictory.
- Two live secrets and two outbound dependencies must be managed on the host.

**Risks**

- Shared hosts sometimes block outgoing HTTP or restrict ports; if so, payments and SMS must be
  handled differently (`O-20`). This must be verified before Phase 5.
- ZarinPal's exact request/response shapes, verification call and callback method vary by
  product/version; the implementation must follow the current official documentation and record the
  version it was written against (not from memory).
- SMS regulation in Iran restricts unsolicited messaging; templates and consent handling must respect
  that, and promotional sending may require an approved pattern.

## Reversal cost

Medium for payments (a second gateway needs a new adapter, a new state mapping and re-verification
tests; stored transaction records and the order state machine stay valid). Low for SMS (a new adapter
and possibly a different template set).

## Verification

- Phase 5: tests covering the full payment state machine, including **duplicate callback**, **forged
  callback with an unverified authority**, **amount mismatch**, **callback for a cancelled order**,
  **gateway timeout**, and **reconciliation of a stuck pending payment** - each asserting that no
  order is created or credited incorrectly.
- Phase 5: an end-to-end run in the gateway's sandbox (if available) or against a real low-value
  transaction, recorded with real evidence, before the phase is declared complete.
- Phase 4: OTP tests covering wrong code, expired code, reused code, code for another mobile number,
  rate-limit enforcement and resend cooldown.
- Phase 5: SMS queue tests covering provider failure, retry, duplicate job and idempotency, asserting
  that one logical event produces exactly one delivered message.
