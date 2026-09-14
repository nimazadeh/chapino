# ADR-0001: Browser-side design engine

- Status: accepted
- Date: 2026-09-14
- Deciders: product owner (constraint `C-6`, `C-7`), engineering agent (design and consequences)
- Rule reference: [architecture](../../../.agents/rules/architecture.md), [design engine](../../../.agents/rules/design-engine.md)
- Related open decisions: `O-14` (print output contract), `O-16` (fonts)

## Context

The product's core is a design studio for customisable apparel: the user places images and text on a
product mockup, previews colour variants, and orders a printed item. The owner has decided that all
of this processing happens in the browser (`C-6`) using vanilla JavaScript only (`C-7`), and that
the application must run on shared PHP hosting (`C-8`) where CPU time, memory, image extensions and
execution time are all limited and unauditable.

Rendering on the server would require an image library (availability unknown, see `O-3`), would
consume the host's CPU quota on every preview, and would make the interactive editor impossible
without a server round trip per gesture.

## Decision

1. The **design document** (a versioned JSON description of the canvas, layers, transforms, text,
   fonts and colours) is the authoritative artefact of the product.
2. All rendering - preview, mockup compositing, colour variants and print-file export - happens in
   the browser with Canvas 2D and vanilla JavaScript.
3. The server stores and validates the design document, and stores the client-produced assets
   (print file, thumbnail, preview image). **The server never rasterizes, composites or resizes a
   design.**
4. The renderer is a pure function of `(design document, output profile) -> canvas/bitmap`, so the
   same document can be re-rendered later at a higher resolution with a different profile without
   user interaction.
5. Server-side validation of a design document is mandatory for integrity (shape, limits, allowed
   fonts, allowed asset references), but it validates the **document**, never its pixels.

## Alternatives considered

| Alternative | Why it was rejected |
| --- | --- |
| Server-side rendering with PHP image extensions | Unknown extension availability on shared hosting (`O-3`), host CPU/memory quotas, per-preview round trips, and it contradicts `C-6` |
| Server-side headless browser rendering | Impossible on shared hosting (`C-8`): no daemons, no Node at runtime, no long processes |
| A third-party rendering/templating service | Foreign service reachability and legality are unresolved (`C-5`, `O-5`), adds cost, latency and personal-data exposure |
| A canvas/editor library | Forbidden by `C-7` (vanilla JS only) and would add an unratified dependency |

## Consequences

**Positive**

- Zero server CPU for rendering; preview is instant and works while the user drags.
- The host requirements stay modest, which is what makes shared hosting viable at all.
- The design document is small, portable, diffable, and can be re-rendered by any future renderer
  (including a future server-side or AI-assisted one) without migrating user data.

**Negative**

- Output quality depends on the user's device and browser; very large exports on low-end phones are
  a real risk and must be managed with explicit limits and progress UI.
- Print fidelity cannot be verified by the server from pixels alone; the export contract
  (`O-14`) must be strict and the pipeline must be tested on real devices.
- The renderer is now a critical, load-bearing piece of client code and needs its own test suite and
  fixtures.

**Risks**

- A browser may render text (especially Persian shaping) slightly differently across versions. The
  mitigation is a documented export contract, a fixed font set shipped with the app, and pixel
  regression fixtures - and, if a discrepancy is ever found in print, the design document allows
  re-export with a corrected profile.

## Reversal cost

Medium. Renderer code would have to be reimplemented server-side, plus a rendering host, plus an
image pipeline - a new architecture, not a patch. The stored design documents, however, would remain
valid, which caps the cost.

## Verification

- Phase 1: the renderer is tested against fixture design documents with assertions on the output
  bitmap (dimensions, DPI-derived pixel size, layer order, text position) and the export is verified
  to be byte-stable for the same input.
- Phase 2: the same design document is verified to produce identical output after a page reload and
  on a second device/browser profile.
- Every phase touching the studio: the export contract (`O-14`) is checked against a real print
  sample before the phase is declared complete.
