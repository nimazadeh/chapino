# chapino

> **Status:** Phase 0 (foundation) is in progress. The application core - configuration, request
> lifecycle, routing, error handling, logging, Persian validation - exists and is exercised by a
> real test suite. Product features do not exist yet. The delivery sequence is in
> [docs/product/roadmap.md](docs/product/roadmap.md).

A print-on-demand customisation platform for Iran: a user builds a design in the browser, previews it
on a product mockup, publishes it as a shareable product page, and an order is placed and paid for
online. The same codebase serves two modes - **SaaS** (self-serve creators) and **B2B**
(organisation accounts).

## Constraints that shape everything

Recorded with identifiers in [docs/engineering/project-context.md](docs/engineering/project-context.md):

- Persian-only RTL user interface (`C-1`, `C-2`)
- Vanilla HTML/CSS/JS frontend, no framework (`C-3`), pure PHP backend, no framework (`C-4`)
- **All design processing happens in the browser** (`C-6`, `C-7`) - the server never renders
- Must install and run on commodity **shared PHP hosting** (`C-8`): no shell, no daemons, no
  in-memory store, no Node at runtime
- Payments via **ZarinPal** (`C-10`), SMS via **Kaveh Negar** (`C-11`)
- **No AI provider in the current scope** (`C-12`): the entry point exists, is visibly disabled, and
  the architecture absorbs a provider later without redesign (`C-13`)

## Layout

```
public/            web root: front controller (index.php) and .htaccess
app/               application code (outside the web root)
  Core/            framework-free core: config, request/response, router, errors, logging, validation
  Controllers/     HTTP controllers
  routes.php       the route table, as an explicit list
  middleware.php   the middleware pipeline, as an explicit ordered list
config/            config.example.php (committed) ; config.php (never committed)
storage/           writable runtime directory: logs, cache, uploads, backups (never committed)
tests/             dependency-free test suite (tests/run.php)
bin/               CLI entry points: smoke.php (installation self-check), later: cron, installer, migrate
tools/dev/         development-only PHP runner - never part of the product
docs/              engineering system, product scope, roadmap, reports
```

## Verify the engineering governance layer

```bash
node .agents/scripts/validate-engineering-os.mjs --strict
```

## Verify the application

This sandbox has no native PHP, so the development runner is used (see
[tools/dev/README.md](tools/dev/README.md)):

```bash
cd tools/dev && npm install          # once

node tools/dev/php.mjs lint                    # parse-check every PHP file
node tools/dev/php.mjs test                    # run the test suite
node tools/dev/php.mjs run bin/smoke.php --config=/path/to/config.php   # installation self-check
```

On a host with native PHP, the equivalent commands are:

```bash
php tests/run.php
php bin/smoke.php --config=/path/config.php
```

### Installation (first steps)

```bash
cp config/config.example.php config/config.php   # then fill in the values
php bin/smoke.php                                # should report 0 failures
```

`config/config.php` holds credentials and host paths and is never committed. The database layer,
migrations, the installer and the cron entry point arrive in the next Phase 0 slice.

## How this repository is governed

All development is performed under the engineering operating system described in
[AGENTS.md](AGENTS.md) and explained for humans in
[docs/engineering/README.md](docs/engineering/README.md). The two rules that matter most in practice:

1. **Code written is not the same as work completed.** Nothing is claimed as working unless it was
   executed and its output observed.
2. **No unverified completion.** If something cannot be verified, it is reported as unverified.
