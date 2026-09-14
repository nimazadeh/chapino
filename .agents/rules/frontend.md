# Rule: Frontend Engineering

**Rule ID:** `frontend`
**Applies to:** every HTML, CSS or browser JavaScript change, and every user-visible behaviour.
**Canonical owner of:** component/markup architecture, client state, browser behaviour, every UI
state (success, error, loading, empty, invalid, unauthorized, not found), accessibility,
responsiveness, client-side performance and asset delivery.
**See also:** [`localization`](localization-fa.md), [`security`](security.md), [`performance`](performance.md), [`architecture`](architecture.md).

---

## 1. The prime directive

> The frontend must not assume that the backend always succeeds.

For **every** feature, the following states must exist, be designed, and be verified:

| State | What the user must experience |
| --- | --- |
| SUCCESS | Confirmation, updated data, clear next step |
| ERROR | A human-readable Persian message, a recovery action, no data loss |
| LOADING | Visible progress, no double submission, no layout jump |
| EMPTY | A first-class screen explaining why it is empty and what to do next |
| INVALID INPUT | Field-level message beside the field, focus moved to the first error |
| UNAUTHORIZED | Clear path to authentication, intended destination preserved |
| FORBIDDEN | Clear denial, no information disclosure about the resource |
| NOT FOUND | A real not-found experience, not a blank page or a crash |
| SERVER ERROR | Safe generic message plus a way to retry; never a raw error dump |
| SLOW NETWORK | Timeout handling, retry affordance, no infinite spinner |

A feature that renders only the happy path is **incomplete**, not "done".

## 2. Markup and structure

1. **Semantic HTML first.** Use the element with the right meaning (`button`, `nav`, `main`, `form`,
   `label`, `table`) before reaching for `div` plus JavaScript.
2. Progressive enhancement is the default posture: content and core flows work with markup and
   server responses; JavaScript enhances them. Never build a screen that is blank without JavaScript
   unless the owner has ratified that trade-off.
3. One `<h1>` per page and a logical heading order; landmarks for navigation, main content and
   footer.
4. Language and direction are declared at the document level and, where content differs, locally.
   See [`localization`](localization-fa.md).
5. No inline event handlers (`onclick="..."`) and no inline style attributes for behaviour. Keep
   behaviour in scripts, presentation in stylesheets.
6. Class and identifier naming follows one documented convention across the codebase; do not
   introduce a second naming scheme.

## 3. Component architecture (framework-free)

- A "component" is a documented, self-contained unit of markup, style and behaviour with an explicit
  input contract (data attributes or an init function) and no knowledge of unrelated features.
- Components do not reach into each other's internal DOM. Interaction happens through documented
  attributes, events or a shared, named state layer.
- Global namespace pollution is forbidden: isolate modules and expose only what is needed.
- One behaviour per module; a module that both fetches data and renders five unrelated widgets is a
  defect.
- Client-side templating, if used at all, is one documented mechanism - never three.

## 4. State management

- Name every piece of state and say where it lives: in the DOM, in a module, in storage, or on the
  server. The server is the source of truth for anything shared or authoritative.
- Derive rather than duplicate. Two copies of the same truth will diverge.
- Never keep secrets, prices, permissions or role decisions client-side as a control.
- Persisted client state (local storage, session storage, cookies) has: a content contract, a
  version, an expiry or cleanup rule, and a safe-read path that tolerates corruption.
- State changes are explicit and traceable: a single named function per transition where practical.

## 5. API integration

- All calls go through one documented client layer: base URL handling, headers, serialization,
  timeout, cancellation, retry policy, error normalization, and correlation identifier.
- Relative URLs. Never hardcode a host, and never call `localhost` from browser code.
- Every call defines: timeout, retry/repeat policy, and what the UI does on each failure class.
- Non-2xx responses are handled explicitly. Never assume success and never rely on "it usually
  returns 200".
- Validate response shape defensively; a hostile or buggy server must not break the page.
- Show the real reason to the user in Persian, and log technical detail where a developer can find
  it - not both at once on screen.

## 6. Forms

- Every input has a programmatically associated label, an input type that matches the data, and
  `autocomplete` hints that make sense for the field.
- Validation is two-layered: immediate UX feedback (client) **and** authoritative validation
  (server). The client must not be the only guard, and must never block a legitimate submission that
  the server would accept without explanation.
- Never clear user input on error. Preserve entered values across failed submissions.
- Prevent double submission: disable the submit control and keep an explicit in-flight state.
- Error messages are placed with the field, describe the fix, and are announced to assistive
  technology. Error text must not rely on colour alone.
- Numeric, date, phone and currency inputs follow Iranian conventions; see
  [`localization`](localization-fa.md).

## 7. Accessibility

Minimum bar for every screen:

- Keyboard: everything operable, logical focus order, visible focus indicator, no focus traps.
- Screen reader: labels for controls, `alt` for meaningful images, empty `alt` for decorative ones,
  landmark regions, live regions for asynchronous updates.
- Contrast: satisfy WCAG AA for text and interactive elements.
- Motion: respect reduced-motion preferences; no animation required to understand content.
- Zoom: usable at 200% zoom and at 320px width without loss of function.
- Errors and status changes are conveyed by text, not only colour or icon.

## 8. Responsive design and mobile

- Design mobile-first; verify at 320px, 375px, 768px, 1024px and a wide desktop width.
- Touch targets are comfortably sized; nothing important sits under the thumb-reach edge case.
- The layout is RTL by default and must not break when a Latin-script value (code, email, URL,
  number) appears inside Persian text.
- Long content, long names and long words must not break the layout.
- Respect safe areas on notched devices where the product is mobile-facing.

## 9. Animations

- Motion must have a purpose: indicate progress, confirm an action, explain a transition.
- Duration and easing are consistent and documented; nothing blocks interaction while animating.
- Animations are interruptible and never cause cumulative layout shift.
- Decorative motion is disabled under reduced-motion preference.

## 10. Performance (client side)

- Ship the minimum: no unused library, no render-blocking third-party asset, no duplicate asset.
- Images: correct dimensions, responsive sources, lazy loading below the fold, modern formats where
  supported.
- Critical CSS first; defer non-critical scripts; avoid layout thrash and forced reflow in loops.
- Debounce or throttle high-frequency events (input, scroll, resize) and cancel stale requests.
- The page must remain responsive during backend slowness; never block the main thread on a fetch.
- Measure before optimizing; see [`performance`](performance.md).

## 11. Network failures

- A failed request produces a visible, Persian, actionable message - never a silent failure and
  never a permanently spinning control.
- Retries are bounded and only for safe or idempotent operations; the user must be able to stop.
- Offline or degraded connectivity must leave the page usable to the extent possible.
- Never lose user-entered data because a request failed.

## 12. Checklist

- [ ] All ten states from section 1 implemented or explicitly declared inapplicable.
- [ ] Semantic markup; no inline handlers; no duplicate templating mechanism.
- [ ] All requests through the single client layer with timeout and error normalization.
- [ ] Forms preserve input on failure, block double submit, and validate on the server too.
- [ ] Keyboard and screen-reader pass; contrast passes; reduced motion respected.
- [ ] Verified at 320/375/768/1024/wide widths, RTL with embedded Latin/digits.
- [ ] No hardcoded host, no `localhost` call, no secret in client code.
- [ ] No unused dependency or asset added.
- [ ] Persian text, dates, numbers and currency follow the localization rule.
