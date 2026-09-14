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
| Product code | Does not exist. The repository contains the engineering governance layer only. |
| Product requirements | Not provided yet. They will arrive as a separate task. |
| Domain, entities, features | Undecided. Nothing may be inferred from the repository name. |
| Technology stack | Partially constrained (see section 2). Not ratified. |
| Database engine | Undecided. |
| Hosting / deployment target | Undecided (region constrained to Iran). |
| Integrations (payment, SMS, storage, AI, maps) | Undecided and unresearched. |
| Application directory layout | Not defined. Do not create speculative folders. |
| CI/CD | Not defined. |
| Test harness | Not defined - no runtime for the application stack is installed in the current sandbox. |

## 2. Ratified constraints (stated by the product owner)

These are binding. They constrain the design; they do not constitute a design.

| ID | Constraint | Source |
| --- | --- | --- |
| `C-1` | The user interface is **Persian (fa-IR) only**. No other user-visible language. | Owner instruction |
| `C-2` | The UI is **RTL-first**. | Implied by `C-1` and stated by the owner |
| `C-3` | The frontend uses **vanilla HTML, CSS and JavaScript** - no frontend framework. | Owner instruction |
| `C-4` | The backend uses **pure PHP** - no backend framework. | Owner instruction |
| `C-5` | The product is intended for use **inside Iran**. | Owner instruction |

Consequences that follow directly from these constraints and are therefore also binding:

- Persian text handling, Persian digits, Jalali calendar display, Iranian input formats and Persian
  search ordering are product requirements, not optional polish. See the
  [localization rule](../../.agents/rules/localization-fa.md).
- Network egress, third-party services and hosting must be reachable and permissible inside Iran.
  A foreign service may not be assumed at all; it becomes an open decision (`O-5`).
- No framework may be introduced to "simplify" work. Framework-free does not mean structure-free:
  the architecture must supply explicit structure that the framework would have supplied
  (routing, request handling, validation, error handling, templating boundaries).

## 3. Open decisions (must be raised, not assumed)

Each item below **requires an owner decision**. An agent must not silently choose one; it must ask,
and record the answer in this file.

| ID | Open decision | Why it matters | Blocks |
| --- | --- | --- | --- |
| `O-1` | What is the product? Domain, users, core value, feature scope | Everything downstream | All implementation |
| `O-2` | Database engine and access approach (which engine, and whether a thin data layer is written by hand) | Schema, queries, migrations, Persian collation and search ordering | Schema, storage layer, migrations |
| `O-3` | PHP version and extension availability in the target environment | Language features, security support window, available APIs | All backend code |
| `O-4` | Hosting, deployment model and environment topology (single server, panel-managed host, container) | Configuration, deployment steps, file permissions, TLS | Deployment, config, release process |
| `O-5` | Which third-party services are permitted, and their Iranian availability (payment gateway, SMS/OTP, object storage, CDN, email, AI, maps) | Integrations, egress, compliance, reliability | All external features |
| `O-6` | Authentication model (password, OTP, both) and whether a national-identity or mobile-verification flow is required | Auth design, session model, rate limiting | Auth, authorization, onboarding |
| `O-7` | Which browsers and devices must be supported (including older Android WebViews common in Iran) | JavaScript feature budget, CSS strategy, testing matrix | Frontend architecture |
| `O-8` | Money, currency and payment handling rules (currency, rounding, refunds, invoice requirements) | Pricing model, exact types, financial correctness | Any commerce feature |
| `O-9` | Personal data policy: what is stored, legal basis, retention period, deletion flow | Schema, retention, deletion, backups | Data model, user features |
| `O-10` | Traffic, data volume and concurrency expectations | Indexing, caching, architecture decisions | Performance design |
| `O-11` | Test tooling by stack (PHP test runner, browser automation) and how it is installed | Ability to verify anything automatically | QA, release verification |
| `O-12` | Required non-functional targets: latency budget, uptime expectation, backup and recovery objectives | Verifiability of releases, performance work | Release criteria |

## 4. How decisions are recorded once answered

- A ratified **technology or architecture** choice becomes an ADR in
  `docs/engineering/adr/ADR-<number>-<slug>.md`, created from
  [templates/adr.md](templates/adr.md), and is removed from the open list here.
- A **product scope** decision is recorded as a requirements document under `docs/` when the owner
  provides it, and linked from this file.
- A **constraint** change is recorded in section 2 with a new ID, the date, and who stated it.

## 5. Rules for working in this state

1. **Do not invent.** No feature, entity, endpoint, table, provider or integration may be created
   because it "seems needed". If it is not in the ratified requirements, it is not in scope.
2. **Do not decide open items silently.** If implementation requires an answer to `O-1` .. `O-12`,
   stop and ask, stating which option you would recommend and why. Proposing is encouraged; deciding
   is not permitted.
3. **Do not create speculative structure.** No placeholder application folders, no empty modules, no
   "for later" abstractions. Structure is created when the requirement that needs it exists.
4. **Record what you learn.** Facts discovered about the environment (installed runtimes, available
   extensions, network reachability) belong in the report and, when durable, in this file.
5. **Respect the constraints in section 2 even under pressure.** A deadline, a convenience, or a
   familiar habit is not a reason to introduce a framework, a foreign backend service or an English
   UI string.

## 6. Environment facts (verified in the current sandbox)

These were observed, not assumed, on 2026-09-14:

| Fact | Value |
| --- | --- |
| `node` | v22.22.3 (available) |
| `npm` | 10.9.8 (available) |
| `python3` | 3.11.2 (available) |
| `git` | 2.39.5 (available) |
| `jq` | 1.6 (available) |
| `php` | **not installed** - PHP code cannot be executed or syntax-checked here |
| `composer` | **not installed** |
| Browser automation | not available |

Implication: while `php` is unavailable, backend changes cannot be executed or even syntax-checked
in this environment. That limitation must be stated in every report that touches PHP, and it forces
`PASS WITH RISKS` at best for such work. See `O-3` and `O-11`.
