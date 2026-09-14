# ADR-0007: Settings belong to the operator, seeds are create-only

- Status: accepted
- Date: 2026-09-14
- Deciders: implementer, under the owner constraints (`C-8`, `C-14`) and the phase plan
- Related: [ADR-0002](ADR-0002-shared-hosting-target.md), `O-14`, `O-15`, `O-6`

## Context

The product has two kinds of configuration and, until this decision, only one home for them:

- what the **host** decides: database credentials, filesystem paths, session storage, the environment
  name. These are written once by the installer, they are credentials, and a mistake in them breaks
  the installation (`config/config.php`);
- what the **operator** decides: which product is printed, how large the print area is, at what
  resolution, and later the commission rate and quota numbers. These change during operation, they
  are not secrets, and a mistake in them is a wrong order or a wrong price (`settings` table).

Mixing both in one file means renaming the site requires editing the file that holds the database
password. Mixing them the other way - credentials in the database - means a database dump leaks the
host's secrets. Neither is acceptable.

There is a second question, about how defaults reach an installation. A migration is recorded and
runs once; a value that must exist on every installation, including one whose database is copied by
hand, cannot be expressed as "run once and forget".

## Decision

1. **`config/config.php` holds host decisions and credentials; the `settings` table holds operator
   decisions.** A setting is never a secret, and the capability report never prints either.
2. **Settings are stored as text** with typed accessors (`Settings::int()`, `Settings::bool()`). A
   "boolean" column that SQLite and MySQL store differently is two interpretations of one fact; the
   accessor is the single interpretation. A value that is not a valid integer falls back to the
   caller's default instead of being cast into a plausible number.
3. **Seed files declare defaults; `Seeder` writes them create-only.** A default is written **only
   when the key is absent**, so re-running the seeder can never undo an operator's decision. This is
   also why the seeder has no `--force`: the destructive option would have no legitimate use.
4. **A default whose value is an owner decision is seeded EMPTY and marked `owner_input`.** The
   installation gets the shape the code expects, the operator gets an explicit list of what still
   needs a decision, and no agent invents a price, a commission or a tax rule.
5. **`--status` prints stored values, never defaults.** A report that shows the default next to the
   word "existing" is a report that lies about the installation it describes.
6. **Server-protection limits (rate limits, OTP quotas) stay in `config/config.php`.** A limit that
   can be relaxed from the admin panel is a limit an attacker who steals an admin session can
   relax. Where they live may be revisited when the panel exists (Phase 5); they must not be
   duplicated in both places before then.

## Alternatives rejected

- **Putting everything in `config/config.php`:** the operator cannot change the print contract or the
  site name without editing a credentials file, and on shared hosting that file is often not
  writable at all.
- **Putting credentials in the `settings` table:** a database dump (backups, a support request, a
  moved installation) would carry the payment gateway's merchant id and the SMS API key.
- **`INSERT ... ON DUPLICATE KEY UPDATE` for seeding** (the obvious upsert): it turns a re-run into a
  destructive act - every operator change silently reverts to the default.
- **Recording applied seeds like migrations:** unnecessary complexity once the write is create-only,
  and it would make "apply the defaults again after a manual database copy" impossible.
- **JSON blobs in a single settings row:** unreadable in the panel that Phase 5 will build, and one
  malformed character would take out every setting at once.

## Consequences

- A fresh installation needs two steps (`bin/migrate.php` then `bin/seed.php`), and the installer runs
  both; the split is visible in the output rather than hidden behind one command.
- Changing a shipped default does **not** update installations that already hold the key. That is
  deliberate, and it means a changed contract needs a migration (for a schema-like change) or an
  explicit operator action (for a value) - never a silent edit of the seed file.
- Settings have no admin UI yet (Phase 5). Until then, changing them requires `php bin/seed.php`
  (create-only, so not enough on its own) or direct SQL; this limitation is recorded in the slice 5
  report rather than hidden.
- The seeder must stay small and honest: a key that nothing reads yet is not seeded, because a value
  that looks authoritative and does nothing is worse than an absent one.
