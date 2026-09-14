# Project Context

The single source of truth for **what is decided**, **what is constrained**, and **what is still
open** about this project. Every agent and engineer reads this before making any decision that
affects the shape of the product.

This file must be updated whenever a decision is made or a question is answered.
It must never contain an assumption that has not been ratified.

---

## 1. Current state

| Item | State |
| --- | --- |
| Product requirements | **Provided (high level).** See `C-15` and [the phase roadmap](../product/roadmap.md). Detail questions are listed in section 4. |
| Product code | **Exists, Phase 0 in progress.** Slice 1 (core: configuration, routing, error handling, logging, Persian validation) and slice 2 (data layer, migrations, installer, environment report) are delivered. Reports: [slice 1](reports/2026-09-14-phase-0-slice-1-core.md), [slice 2](reports/2026-09-14-phase-0-slice-2-database.md). |
| Architecture decisions | Five ratified: [ADR-0001](adr/ADR-0001-browser-side-design-engine.md), [ADR-0002](adr/ADR-0002-shared-hosting-target.md), [ADR-0003](adr/ADR-0003-deferred-ai-provider-seam.md), [ADR-0004](adr/ADR-0004-iran-region-integrations.md), [ADR-0005](adr/ADR-0005-data-layer-and-migrations.md). |
| Technology stack | Constrained and mostly decided: vanilla HTML/CSS/JS frontend, pure PHP backend, shared hosting deployment. Database engine and PHP version are **provisional assumptions** until a host exists (`O-2`, `O-3`, `O-20`); the code must satisfy both while they remain assumptions. |
| Hosting / deployment target | Shape decided (shared PHP hosting, inside Iran). **No real host exists yet**: the owner tests on localhost (XAMPP, Laragon, `php -S`), so everything is verified locally and reported as unverified on the target (`O-4`, `O-20`). |
| Integrations | ZarinPal (payments) and Kaveh Negar (SMS) ratified. Fulfilment is outsourced to a third-party print house that is not yet chosen (`C-16`, `O-13`). No AI provider in the current scope; a seam is required (ADR-0003). |
| Application directory layout | Defined and committed: `app/` (PSR-4 `App\`), `public/` (the only web-exposed directory), `bin/` (installer, migrate, environment report, self-check), `config/`, `database/migrations/`, `storage/` (runtime state, outside the web root), `tests/`, `tools/dev/` (development-only tooling). See [README](../../README.md). |
| Test harness | Defined and running: `node tools/dev/php.mjs lint` and `node tools/dev/php.mjs test` execute the real PHP on a WebAssembly runtime, so "verified" means executed. The product itself never needs Node (`O-11` closed for the server side; browser-side tests arrive with the design engine in Phase 1). |

## 2. Ratified constraints (stated by the product owner)

These are binding. They constrain the design; they do not constitute a design.

| ID | Constraint | Source |
| --- | --- | --- |
| `C-1` | The user interface is **Persian (fa-IR) only**. No other user-visible language. | Owner instruction |
| `C-2` | The UI is **RTL-first**. | Implied by `C-1` and stated by the owner |
| `C-3` | The frontend uses **vanilla HTML, CSS and JavaScript** - no frontend framework. | Owner instruction |
| `C-4` | The backend uses **pure PHP** - no backend framework. | Owner instruction |
| `C-5` | The product is intended for use **inside Iran**. | Owner instruction |
| `C-6` | **All design processing happens in the browser.** Rendering, composition, colour variants, mockup preview and print-file export are client-side. PHP never renders or rasterizes a design. | Owner instruction |
| `C-7` | **The design studio is implemented entirely in vanilla JavaScript.** No canvas/editor library, no framework, no build-time bundler requirement. | Owner instruction |
| `C-8` | The product must be **installable and fully runnable on commodity shared PHP hosting** (no shell, no daemons, no Redis, no Node at runtime). | Owner instruction |
| `C-9` | PHP is used for **backend concerns only**: routing, request handling, server-side validation, authentication/authorization, business logic, persistence, integration calls. | Owner instruction |
| `C-10` | Payments are processed through **ZarinPal**. | Owner instruction |
| `C-11` | SMS and verification codes are sent through **Kaveh Negar**. | Owner instruction |
| `C-12` | **No AI image-generation API is used in the current scope.** The AI entry point exists in the UI but is visibly **disabled**. | Owner instruction |
| `C-13` | The architecture must make it **straightforward to add an AI image-generation provider later** and switch it on without redesign. | Owner instruction |
| `C-14` | The product is offered in **two modes: SaaS (self-serve) and B2B (business accounts)**, on one codebase. | Owner instruction |
| `C-15` | Product shape: a **print-on-demand customisation platform** (as demonstrated in the referenced product demo, 00:00-03:44): design studio with image/text/colour tools, preview on a product mockup, save as a listing, shareable product page, checkout, order tracking. | Owner instruction + referenced demo |

| `C-16` | **Fulfilment is outsourced.** Printing and shipping are done by a third-party print house - not by the owner's own business - and that print house is **not chosen yet**. Fulfilment must therefore be a pluggable, configuration-driven integration with a documented contract, and no feature may assume a self-operated print shop. V1 product scope is **t-shirt only**. | Owner instruction (2026-09-14) |

Consequences that follow directly from these constraints and are therefore also binding:

- Persian text handling, Persian digits, Jalali calendar display, Iranian input formats and Persian
  search ordering are product requirements, not optional polish. See the
  [localization rule](../../.agents/rules/localization-fa.md).
- Network egress, third-party services and hosting must be reachable and permissible inside Iran.
  No foreign service may be assumed; see `C-5` and [ADR-0004](adr/ADR-0004-iran-region-integrations.md).
- No framework may be introduced to "simplify" work. Framework-free does not mean structure-free:
  the architecture must supply explicitly what a framework would have supplied (routing, request
  handling, validation, error handling, templating boundaries) - see [ADR-0002](adr/ADR-0002-shared-hosting-target.md).
- Because rendering is client-side (`C-6`), the **design document** stored on the server becomes the
  authoritative artefact, and the print output must be reproducible from it. See the
  [design engine rule](../../.agents/rules/design-engine.md).
- Shared hosting (`C-8`) forbids long-running workers, so every asynchronous capability (SMS
  dispatch, order reconciliation, AI jobs later) must be cron-driven and idempotent.

## 3. Ratified architecture decisions

| ADR | Decision | Status |
| --- | --- | --- |
| [ADR-0001](adr/ADR-0001-browser-side-design-engine.md) | Browser-side design engine; server stores the design document and the exported assets, never renders | accepted |
| [ADR-0002](adr/ADR-0002-shared-hosting-target.md) | Shared-hosting deployment target and the constraints it imposes on every subsystem | accepted |
| [ADR-0003](adr/ADR-0003-deferred-ai-provider-seam.md) | AI image generation as a disabled, provider-agnostic seam | accepted |
| [ADR-0004](adr/ADR-0004-iran-region-integrations.md) | ZarinPal for payments, Kaveh Negar for SMS, with server-side verification of everything | accepted |

## 4. Open decisions (must be raised, not assumed)

Each item below **requires an owner decision or a verified environment fact**. An agent must not
silently choose one; it must ask, and record the answer in this file.

| ID | Open decision | Status | Why it matters | Blocks |
| --- | --- | --- | --- | --- |
| `O-1` | Exact feature scope of the first release | **partially answered** by `C-14`, `C-15`; detail still open | Everything downstream | Roadmap detail |
| `O-2` | Database engine | **provisional** - owner chose to proceed locally; working assumption is **MySQL-compatible (MySQL 5.7+/8 or MariaDB 10.3+)** with `utf8mb4`, to be confirmed from the host panel (`O-20`). The data layer must also run on SQLite for local tests. | Schema, queries, migrations, Persian collation and search ordering | Phase 0 persistence layer |
| `O-3` | PHP version available on the host (and which extensions) | **provisional** - target **PHP 8.1+** with `mbstring`, `pdo`, `json`, `curl`, `openssl`, `zip`; code must avoid anything newer than the minimum supported version, to be confirmed from the host panel (`O-20`) | Language features, security support, extension availability | All backend code |
| `O-4` | Concrete host, panel type, deployment mechanics | **partially answered** by `C-8`. Owner chose local-first development: Phase 0 is built and verified locally against declared assumptions, and the deployment runbook is verified on the real host later | Deployment steps, permissions, TLS, cron availability | Final Phase 0 sign-off |
| `O-5` | Which third-party services are permitted | **answered for payments and SMS** ([ADR-0004](adr/ADR-0004-iran-region-integrations.md)); **open** for AI (deferred), shipping tracking, object storage, email | Integrations, compliance, reliability | Phase 5, 7, 8 |
| `O-6` | Authentication method | **decided (2026-09-14): mobile number + one-time code via Kaveh Negar, no passwords.** No password storage, no password reset flow. SMS cost is borne to mitigate abuse through per-number, per-IP and per-device quotas | Auth design, session model, rate limiting | Phase 3 |
| `O-7` | Browsers and devices that must be supported (including older Android WebViews common in Iran) | **open** | JavaScript feature budget, canvas behaviour, testing matrix | Phase 1, 2 |
| `O-8` | Money rules: currency, rounding, pricing, taxes, refunds | **partially answered** by `C-10`; commercial model still `open` (see `O-15`) | Exact types, financial correctness, invoicing | Phase 5 |
| `O-9` | Personal data policy: what is stored, retention, deletion, consent | **open** | Schema, retention, deletion, backups | Phase 4 |
| `O-10` | Traffic, data volume and concurrency expectations, and whether B2B is sold in the same offer as SaaS | **open - options presented to the owner on 2026-09-14, awaiting a choice.** Nothing is decided: the platform must stay usable at the smallest scale and must not hard-code a B2B promise into pricing, onboarding or the landing page until the owner decides | Indexing, caching, architecture decisions, B2B scope | Phase 0, 6, 7 |
| `O-11` | Test tooling for PHP and for the browser, and how it is installed on a shared host | **open** | Whether any claim can be automatically verified | Phase 0, every phase |
| `O-12` | Non-functional targets: latency budget, uptime expectation, backup/recovery objectives | **open** | Release criteria, performance work | Phase 9 |
| `O-13` | Fulfilment and shipping: who prints, who ships, and whether postal/tracking integration is in scope | **partially answered (2026-09-14)** - the printer is a **third-party print house, not the owner's business**; the specific print house is still unchosen; v1 is t-shirt only. Consequence: fulfilment stays a pluggable integration behind a documented contract (`C-16`), and order statuses must not encode one provider's workflow | Order model, statuses, notifications, Phase 7 scope | Phase 5, 7 |
| `O-14` | Print output contract | **decided (2026-09-14), provisional values:** first product is the **t-shirt**; print areas front/back **30x40 cm**, sleeve **10x10 cm**; **300 DPI**; output **PNG with transparency**; **RGB** colour. Every value is configuration-driven, so the print shop's real numbers can replace them without code changes | The design engine's export contract and print fidelity | Phase 1, 2 |
| `O-15` | Commercial model and B2B shape | **commercial model decided (2026-09-14): hybrid** - a free base plan with commission on sales plus a paid professional subscription. B2B structural shape (organizations, sub-users, private catalogue, price lists, white-label) is still **open** and is a Phase 6 gate | Multi-tenancy design, permissions, pricing | Phase 3 (plans/quotas), Phase 6 (B2B shape) |
| `O-16` | Fonts available in the studio, and their licensing for embedding and print output | **open** | Studio text tool, output legality, asset size | Phase 1, 2 |
| `O-17` | Brand name, product name (Persian and Latin), domain | **open** | All user-visible text, email/SMS sender identity | Phase 0, 3 |
| `O-18` | Purchase flow shape | **decided (2026-09-14): single-item "buy now" first, cart later** - the order model must therefore be designed as an order with items from day one, even though the first release creates one item per order | Order model, tables, UI | Phase 4 (single item), later phase (cart) |
| `O-19` | Invoicing and tax requirements (formal invoice, VAT, retention of financial records) | **open** | Order/payment schema, reports | Phase 5, 7 |
| `O-20` | Verified host capability facts: PHP version, database engine and version, cron availability, connection limits, upload/post size limits, disk quota, outgoing HTTP support, SSL | **open - owner chose local-first development (2026-09-14).** Phase 0 proceeds against the declared assumptions in `O-2`/`O-3`, an installer capability check is written now, and the facts are filled in when the owner runs it on the real host. Until then, PHP-dependent work is reported as verified-locally, unverified-on-target | Final Phase 0 sign-off; every deployment step | Phase 0 |

## 5. How decisions are recorded once answered

- A ratified **technology or architecture** choice becomes an ADR in
  `docs/engineering/adr/ADR-<number>-<slug>.md`, created from
  [templates/adr.md](../engineering/templates/adr.md), and this file is updated to point at it.
- A **product scope** decision is recorded as a requirements document under `docs/product/` and
  linked from here; the affected phase in [the roadmap](../product/roadmap.md) is updated.
- A **constraint** change is recorded in section 2 with a new ID, the date, and who stated it.
- An **environment fact** is recorded in section 7 (or `O-20`) with how it was verified.

## 6. Rules for working in this state

1. **Do not invent.** No feature, entity, endpoint, table, provider or integration may be created
   because it "seems needed". If it is not in `C-1` .. `C-15` or a ratified ADR, it is not in scope.
2. **Do not decide open items silently.** If implementation requires an answer to any `O-n`, stop and
   ask, stating which option you would recommend and why. Proposing is encouraged; deciding is not.
3. **Do not create speculative structure.** No placeholder application folders, no empty modules, no
   "for later" abstractions. Structure is created in the phase that needs it.
4. **Respect the constraints even under pressure.** A deadline, a convenience, or a familiar habit is
   not a reason to introduce a framework, a foreign backend service, a server-side renderer, or an
   English UI string.
5. **Phase discipline.** Work proceeds phase by phase as defined in
   [the roadmap](../product/roadmap.md). A phase does not start before the previous phase's exit
   criteria pass, and every phase ends with verification and a report.

## 7. Environment facts (verified in the current sandbox)

These were observed, not assumed, on 2026-09-14:

| Fact | Value |
| --- | --- |
| `node` | v22.22.3 (available - usable for development-time tooling and tests, **never** as a runtime dependency of the product) |
| `npm` | 10.9.8 (available) |
| `python3` | 3.11.2 (available) |
| `git` | 2.39.5 (available) |
| `jq` | 1.6 (available) |
| `php` | **not installed** - PHP code cannot be executed or syntax-checked here |
| `composer` | **not installed** (and not required: `C-4` implies no dependency manager at runtime) |
| Browser automation | not available |

Implication: while `php` is unavailable **and** the PHP version of the target host is unknown
(`O-3`), backend changes cannot be executed or even syntax-checked here. That limitation must be
stated in every report that touches PHP, and it forces `PASS WITH RISKS` at best for such work.
The first Phase 0 deliverable is therefore a **host capability report** that closes `O-20`, `O-2`
and `O-3`.
