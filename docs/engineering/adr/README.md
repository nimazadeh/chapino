# Architecture Decision Records

Durable records of decisions that were **actually made**, their alternatives and consequences.

- Created from [../templates/adr.md](../templates/adr.md).
- Named `ADR-<number>-<slug>.md`, numbered sequentially, never renumbered.
- Statuses: `proposed`, `accepted`, or `superseded by ADR-<n>`. Superseding an ADR means adding a new
  one, never editing the old one into agreement with the present.
- When an ADR is accepted, remove the corresponding open item from
  [../project-context.md](../project-context.md) (or mark it resolved and link the ADR).

Triggers and format are defined in
[.agents/rules/architecture.md](../../../.agents/rules/architecture.md#6-architectural-decision-making).
An ADR never records what has not been decided, and never a decision reserved to the product owner
(technology stack, hosting, integrations - see `O-1` .. `O-12` in project-context.md).

## Index

| ADR | Title | Status | Date |
| --- | --- | --- | --- |
| [ADR-0001](ADR-0001-browser-side-design-engine.md) | Browser-side design engine | accepted | 2026-09-14 |
| [ADR-0002](ADR-0002-shared-hosting-target.md) | Shared-hosting deployment target | accepted | 2026-09-14 |
| [ADR-0003](ADR-0003-deferred-ai-provider-seam.md) | AI image generation as a disabled, provider-agnostic seam | accepted | 2026-09-14 |
| [ADR-0004](ADR-0004-iran-region-integrations.md) | ZarinPal for payments and Kaveh Negar for SMS, verified server-side | accepted | 2026-09-14 |
| [ADR-0005](ADR-0005-data-layer-and-migrations.md) | Hand-written data layer, dialect-aware schema builder, plain-PHP migrations | accepted | 2026-09-14 |
| [ADR-0006](ADR-0006-sessions-csrf-rate-limiting-and-job-queue.md) | PHP-native sessions with a data-backed identity, deny-by-default CSRF, database-backed rate limiting, cron-driven job queue | accepted | 2026-09-14 |
| [ADR-0007](ADR-0007-settings-and-seeds.md) | Config holds host decisions and credentials; settings hold operator decisions; seeds are create-only and owner-input keys stay empty | accepted | 2026-09-14 |
