# Rule: Browser-Side Design Engine

**Rule ID:** `design-engine`
**Applies to:** the visual editor, the renderer, the print export, the design document format, and
any code that produces or consumes visual output.
**Canonical owner of:** the design document contract, renderer purity, coordinate and resolution
model, export/print contract, editor interaction rules, client-side render budget.
**See also:** [ADR-0001](../../docs/engineering/adr/ADR-0001-browser-side-design-engine.md), [`frontend`](frontend.md),
[`localization`](localization-fa.md), [`security`](security.md), [`performance`](performance.md).
**Rule text lives at:** `.agents/rules/design-engine.md`; decision record at
`docs/engineering/adr/ADR-0001-browser-side-design-engine.md`.

---

## 1. The design document is the product

1. The **design document** is a versioned JSON structure describing one design: canvas, layers,
   transforms, text runs, fonts, colours, and references to uploaded assets. It is persisted,
   validated, versioned and never mutated in place without a recorded version bump.
2. Every visual output - thumbnail, mockup preview, colour variant, print file - must be
   **reproducible from the design document plus an output profile**. No output may depend on editor
   session state that is not in the document.
3. The document is **forward-only versioned** (`schemaVersion`). A reader must reject unknown
   versions loudly rather than guess, and a migration for an older version is a documented function
   with tests, never an ad-hoc `if`.
4. The document is small and portable: asset **references** are stored, never embedded binaries.
   A size limit is enforced on save (server-side, not only client-side).
5. Server-side validation of the document is mandatory (shape, limits, allowed fonts, allowed asset
   references, coordinate bounds) because an uploaded document is untrusted input. Validation never
   renders pixels.

## 2. Renderer purity and resolution model

1. The renderer is a **pure function**: `render(designDocument, outputProfile) -> canvas`.
   Same inputs, same output - no hidden state, no ambient font loading, no timing dependence.
2. Coordinates are stored in a **device-independent unit tied to the physical print area** (for
   example millimetres), never in screen pixels. Screen and print are two output profiles over the
   same geometry; this is what makes a preview preview a print.
3. Never bake the viewport into the document. Zoom and pan are view state only.
4. Print export must state its **pixel dimensions derived from the required DPI** and be validated
   against the print contract (owner decision `O-14`). If the requested output exceeds what the device can safely
   rasterize, the export must fail with a Persian, actionable message - not silently produce a
   lower-quality file.
5. Colour handling is explicit: the export states its colour space and how transparency is treated.
   Never promise print colour fidelity that the pipeline cannot deliver.
6. Fonts shipped with the app only (owner decision `O-16`); no remote font loading, no fallback to an arbitrary
   system font for Persian text, because a silent fallback changes the print output.

## 3. Editor interaction rules

1. Every gesture has a defined result: select, move, scale, rotate, reorder, delete, duplicate.
   Behaviour at the edges (dragging outside the print area, scaling below/above bounds, rotating text)
   is defined and bounded, never left "as the library does it".
2. **Undo and redo are mandatory** for every mutating action, with a bounded history. An editor
   without reliable undo is not shippable.
3. The editor never loses work: unsaved changes are recoverable after a reload or a crash
   (autosave to local storage or server-side draft, with a documented conflict rule).
4. Snapping, guides and touch targets are defined for touch first, since most Iranian users are on
   mobile.
5. Keyboard and pointer parity: everything achievable by dragging is achievable by keyboard for
   accessibility.
6. Text input supports Persian fully: shaping, RTL, ZWNJ, alignment, and direction per text layer;
   numeric content inside a Persian layer follows the [localization rule](localization-fa.md).
7. Uploaded images are validated client-side (type, dimensions, size) **and** server-side; a
   rejected upload must explain the reason in Persian and preserve the rest of the design.

## 4. Performance budget (client)

- The renderer must stay responsive during drag: no full-resolution re-render per pointer move; use
  a scaled preview pass and render at full resolution only on export or idle.
- Large exports must be chunked or off the main thread where the browser allows, with progress shown
  and the operation cancellable.
- Image assets referenced by a design are loaded once, reused, and released when no longer needed;
  memory growth across many designs in one session is a defect (see [`performance`](performance.md)).
- Editor JavaScript ships as plain files, no build step, no unused dependency (`C-3`, `C-7`).

## 5. Where the seam to the server is

| Concern | Client | Server |
| --- | --- | --- |
| Rendering, compositing, colour variants, print export | **authoritative** | never performs it |
| Design document validation for integrity and limits | advisory (fast feedback) | **authoritative** |
| Asset upload validation | advisory (fast feedback) | **authoritative** |
| Price, availability, permission, order state | never authoritative | **authoritative** |
| Print contract (sizes, DPI, forbidden values) | consumed as data | **authoritative source** |

## 6. Checklist

- [ ] The change is expressible in the design document, or the document schema was version-bumped.
- [ ] The renderer remains a pure function; no new hidden state or timing dependence.
- [ ] Geometry in device-independent units; no pixels from the viewport baked into the document.
- [ ] Print export validated against the print contract, with DPI-derived dimensions stated.
- [ ] Undo/redo covers every new mutating action; history is bounded.
- [ ] A reload or crash cannot lose the user's work.
- [ ] Persian text fully supported in any new visual feature (shaping, RTL, ZWNJ, numerals).
- [ ] Assets and fonts are self-hosted; no remote fetch introduced.
- [ ] Render work is not performed per pointer move; heavy export is cancellable with progress.
- [ ] All limits (document size, asset size, layer count, export size) are enforced server-side too.
- [ ] Fixtures exist for the new behaviour and output is asserted, not eyeballed only.
