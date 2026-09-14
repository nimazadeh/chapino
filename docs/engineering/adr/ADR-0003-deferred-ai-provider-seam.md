# ADR-0003: AI image generation as a disabled, provider-agnostic seam

- Status: accepted
- Date: 2026-09-14
- Deciders: product owner (constraint `C-12`, `C-13`), engineering agent (design and consequences)
- Rule reference: [architecture](../../../.agents/rules/architecture.md), [security](../../../.agents/rules/security.md)
- Related open decisions: `O-5` (permitted third-party services), `O-13` (cost ownership if AI is billed)

## Context

The reference product generates artwork from a text prompt and drops it onto the mockup. The owner
has decided that **no AI image-generation API will be used in the current scope** (`C-12`), but the
UI must still show the AI entry point, visibly disabled, and the architecture must make enabling a
provider later a contained change rather than a redesign (`C-13`).

Constraints that apply: shared hosting with no worker (`C-8`, [ADR-0002](ADR-0002-shared-hosting-target.md)),
all rendering in the browser ([ADR-0001](ADR-0001-browser-side-design-engine.md)), Iran-only
availability (`C-5`), and server-side authority over every action (`C-9`).

Image generation differs structurally from the rest of the product: it is slow (seconds), it costs
money per call, it can fail transiently, and it must be captured server-side because the result has
to become an immutable asset referenced by a design document.

## Decision

1. **The seam exists now, the provider does not.** A single internal interface is defined in Phase 1
   with no implementation behind it. Nothing in the product may call a provider directly.
2. **The feature is off by configuration, not by code removal.** A server-side capability flag
   (`ai.enabled = false` by default), the provider selection, and credentials all come from
   configuration. The API reports capability to the client; the client renders the AI control as
   **disabled with a Persian explanation of the reason and the benefit** - never as a broken button.
3. **The disabled path is a real path.** The endpoint exists and returns a documented
   "capability unavailable" response; the server rejects the request regardless of what the client
   sends. Security never depends on the control being disabled in the UI.
4. **Generation is asynchronous by contract.** A generation is persisted as a job row with
   `queued -> running -> succeeded | failed`, polled by the client, and executed by the cron worker
   of [ADR-0002](ADR-0002-shared-hosting-target.md). The provider interface is therefore defined as
   job-shaped from day one, so a future provider that is slow or callback-based needs no change above
   it.
5. **The provider interface covers**: submit a job, poll/query its status, receive results as
   binaries or URLs, cancel, and report cost/units. The interface never leaks provider vocabulary
   into the domain: the domain speaks of "generation jobs", "prompts" and "generated assets".
6. **Results are captured, not hot-linked.** When a provider is enabled later, its output must be
   downloaded and stored as an asset owned by the platform, because external URLs expire and are
   unreachable or impermissible in the Iranian context.
7. **Prompts are user content**: stored, moderated as needed, size-bounded, and validated like any
   other input.
8. **Costs are bounded by design**: per-user quotas, prompt length limits, max concurrent jobs per
   account, and a global kill switch, all configured server-side.

## Alternatives considered

| Alternative | Why it was rejected |
| --- | --- |
| Hide the AI feature entirely until a provider is chosen | Contradicts `C-12`: the owner wants the control visible but disabled |
| Implement a specific provider now, disabled | Locks the design to one provider's flow (synchronous, callback-based, URL-based) before the provider is chosen (`O-5`) and before regional availability is known |
| Build the AI feature client-side only, calling a provider from the browser | Exposes credentials, removes server-side quota control, and violates the trust boundary in the [security rule](../../../.agents/rules/security.md) |
| Do the work later as a fresh feature | The owner explicitly required the architecture to absorb it (`C-13`); retrofitting job semantics into a synchronous product later is exactly the redesign to avoid |

## Consequences

**Positive**

- Enabling AI later is: implement one class, set one flag, add credentials, configure a quota. No
  schema change, no UI rework, no new infrastructure.
- The disabled state is honest and testable rather than a dead button.
- Job semantics (idempotency, retries, status visibility, quotas) are shared with the rest of the
  product's asynchronous work.

**Negative**

- We carry an interface, a job type and a disabled endpoint that have no provider yet - a small,
  documented amount of unused structure, justified by an explicit owner requirement.
- The disabled UI needs real Persian copy explaining the state, and that copy will need review when
  the feature goes live.

**Risks**

- The eventual provider may have a fundamentally different flow (for example, an asynchronous
  webhook with signed callbacks). The job-shaped interface absorbs this, but reaching the webhook
  endpoint must be possible from the host (`O-20`).
- A generous provider free tier could tempt quota logic to be skipped; quotas are part of the seam
  from the start precisely to prevent that.

## Reversal cost

Very low. Deleting the seam is a contained change; the maintenance cost of keeping it is one
interface, one job type and one configuration flag.

## Verification

- Phase 1: with `ai.enabled = false`, a test asserts that the client renders a disabled control with
  Persian explanatory text, and that a direct request to the capability endpoint is rejected
  server-side with the documented error - proving the control is not the security boundary.
- Phase 1: a test double implementing the provider interface is exercised end to end through the job
  lifecycle (`queued -> running -> succeeded`, and a forced `failed`), proving the seam works without
  any provider.
- Later, when a provider is chosen: the same tests run against the real adapter, and the "capture,
  do not hot-link" rule is verified by asserting the asset is stored locally.
