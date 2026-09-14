# Rule: Database Engineering

**Rule ID:** `database`
**Applies to:** every schema, migration, index, constraint, query and data-repair change.
**Canonical owner of:** schema design, migrations and their safety, constraints and integrity,
indexing strategy, data integrity and export/backup considerations.
**See also:** [`backend`](backend.md), [`performance`](performance.md), [`security`](security.md).

> **Engine note:** the database engine is **not chosen yet** and must not be assumed. Phrases such as
> "use InnoDB", "use `utf8mb4_..._ci`", "add a partial index" are engine-specific and only apply
> once the owner ratifies the engine. Until then, record requirements in engine-neutral terms and
> ask. Persian text handling (collation, sorting, search forms) is a first-class requirement - see
> [`localization`](localization-fa.md).

---

## 1. Design requirements for every table

For each table, be able to state:

- **Purpose** in one sentence, and which module owns it.
- **Primary key**: what uniquely identifies a row, and whether it is stable and non-guessable.
- **Columns**: type, nullability, default, and why. Nullable columns must have a documented meaning
  for NULL, distinct from empty string, zero or a sentinel value.
- **Relationships**: cardinality, and the referential action on delete and on update.
- **Constraints**: unique, format, range, and state-transition rules enforced where the engine can
  enforce them, not only in application code.
- **Indexes**: every index justified by a query that exists today, with the columns in a useful
  order.
- **Volume expectation**: how many rows in a year, and what that implies for queries and archival.
- **Sensitivity**: what personal or sensitive data it holds, who may read it, and how long it is
  kept.

## 2. Integrity rules

1. **The database is the last line of defence.** Enforce uniqueness, foreign keys and required
   fields with constraints, so that application bugs cannot corrupt data.
2. **Foreign keys are declared.** Orphan rows are a defect; if an engine or legacy constraint makes
   a real foreign key impossible, record the ADR and compensate with a documented repair job.
3. **No implicit nullability.** Every nullable column is a deliberate decision with a reason.
4. **Defaults are meaningful, not accidental.** A default value must be a value you would accept in
   production data.
5. **Cascades are explicit.** State on every foreign key whether deletion cascades, restricts or
   nullifies - never leave it to a default you did not choose.
6. **Money, quantities and rates use exact types**, never floating point, and store the unit and
   currency explicitly.
7. **Timestamps store an unambiguous instant** (or an explicit, documented local-time rule), with a
   consistent convention across the schema. Persian calendar presentation is a formatting concern,
   not a storage concern; see [`localization`](localization-fa.md).
8. **Soft delete, if used, is a documented convention** applied consistently, with every read path
   aware of it. Half-applied soft delete is a defect.
9. **No business rule enforced only in the UI.**

## 3. Queries

- Parameterized always; see [`backend`](backend.md#13-mandatory-defect-hunt).
- **No N+1**: fetch collections in one bounded query or a small fixed number of queries.
- **No `SELECT *`** in application code: select the columns you use, so schema and index changes do
  not silently change behaviour or payload size.
- **Always bound result sets.** Pagination, `LIMIT`, and a documented maximum page size are
  mandatory for anything a user can grow.
- Filter, join and sort columns must be covered by an index that matches the access pattern.
- Understand the plan for any query touching a large or growing table; do not guess at selectivity.
- Aggregations over growing tables must be reviewed for cost, or precomputed.

## 4. Indexing

For each index, record: the query it serves, the column order, and whether it is unique. Remove
indexes with no supporting query - they cost writes and space. Specifically check:

- Filters and joins on foreign keys.
- Sort columns, especially with pagination (the combination must be index-covered to avoid a sort of
  the whole table).
- Uniqueness that is currently enforced only in application code.
- Case- and accent-sensitivity requirements for search over Persian text (this is engine-specific
  and is an open question until the engine is ratified).

## 5. Migrations

Every migration must be reviewable, reversible and safe to run on live data.

**Mandatory safety review before writing a migration:**

- [ ] Can it be applied while the application is running, or does it require downtime? State which.
- [ ] Does it lock a table, and for how long on the expected data volume?
- [ ] Is it reversible? If not, why is that acceptable and what is the recovery plan?
- [ ] Does it drop, rename or retype a column or table? If yes, treat it as data-destroying.
- [ ] Does it change nullability, defaults or constraints on existing data? Are existing rows valid
      under the new rule, and what happens to the ones that are not?
- [ ] Does it backfill data? Is the backfill batched, resumable and observable?
- [ ] Is the application change compatible with both old and new schema during rollout (expand /
      migrate / contract)?
- [ ] Are dependent objects (indexes, views, foreign keys, generated values, caches) updated?
- [ ] Was it tested on a **copy** of realistic data, not only on an empty database?

**Rules**

1. Never casually destroy existing data. Dropping a table or column is a data-loss operation and
   requires explicit owner authorization.
2. Never rename a column in one step when the application still uses the old name; expand, migrate,
   then contract.
3. Never edit a migration that has already been applied to a shared environment; add a new one.
4. Migrations are idempotent where the engine allows, and must be safe to re-run after a partial
   failure.
5. Take a restorable backup before any destructive or irreversible migration, and record where it is
   and how to restore it.
6. Roll forward by preference; a rollback plan must still exist and be written down.
7. Migrations and their backfills are logged and observable; a silent partial application is a
   defect.

## 6. Data handling

- Personal data is minimized, purpose-bound, and retained for a defined period. Deletion requests
  must be executable, including in backups where the owner's policy requires it.
- Seed and fixture data contains no real personal data and no production credentials.
- Restoration must be practiced, not assumed: an untested backup is not a backup. State clearly in
  the report when restorability could not be verified.
- Data exports must be authorized, audited and bounded in size.

## 7. Checklist

- [ ] Table and column purposes, nullability and defaults documented and intentional.
- [ ] Relationships, foreign keys, uniqueness and cascades declared explicitly.
- [ ] Exact types for money and quantities; unambiguous timestamps.
- [ ] Every index justified by a real query; no N+1; no `SELECT *`; all reads bounded.
- [ ] Migration safety checklist completed and the results stated.
- [ ] No destructive change without explicit authorization and a restorable backup.
- [ ] Data volume expectations considered for every new query and index.
- [ ] Persian collation, sort and search requirements recorded as questions if the engine is unset.
