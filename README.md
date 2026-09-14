# chapino

> **Status:** Phase 0 (foundation) is in progress. Delivered so far: the application core
> (configuration, request lifecycle, routing, error handling, logging, Persian validation), the data
> layer with versioned migrations and an installer that needs no shell, and the security foundation
> (session and cookie policy, CSRF, database-backed rate limiting) with a database-backed job queue
> driven by cron. Product features do not exist yet. The delivery sequence is in
> [docs/product/roadmap.md](docs/product/roadmap.md); what remains in Phase 0 is the RTL design
> system, the browser-side test harness and the installation runbook.

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
bin/               CLI entry points: install.php, migrate.php, check-requirements.php,
                   smoke.php (installation self-check), cron.php (runs the job queue),
                   queue.php (inspect and retry jobs)
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
node tools/dev/php.mjs run bin/cron.php                                # run queued jobs once
node tools/dev/php.mjs run bin/queue.php --status                      # queue state for the operator
```

On a host with native PHP, the equivalent commands are:

```bash
php tests/run.php
php bin/smoke.php --config=/path/config.php
```

### Installation (first steps)

```bash
php bin/check-requirements.php                   # what this host supports, before anything is written
php bin/install.php --driver=sqlite --url=http://localhost:8080
php bin/smoke.php                                # should report 0 failures
php bin/cron.php                                 # run the queue once, as the host's cron would
```

`bin/install.php` writes `config/config.php` (refusing to overwrite an existing one without
`--force`), creates the writable runtime directories - `storage/sessions` with mode `0700`, because
session files hold login state - and applies the migrations. Nothing needs a shell beyond running the
command: the same steps can be reproduced through the hosting panel, which is what the installation
runbook will document.

`config/config.php` holds credentials and host paths and is never committed.

The job queue runs on the database and is driven by the host's cron calling `bin/cron.php`; there is
no daemon to keep alive (`bin/queue.php --status` shows what is waiting, what failed and why).

## How this repository is governed

All development is performed under the engineering operating system described in
[AGENTS.md](AGENTS.md) and explained for humans in
[docs/engineering/README.md](docs/engineering/README.md). The two rules that matter most in practice:

1. **Code written is not the same as work completed.** Nothing is claimed as working unless it was
   executed and its output observed.
2. **No unverified completion.** If something cannot be verified, it is reported as unverified.
