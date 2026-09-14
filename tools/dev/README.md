# Development tooling (never part of the product)

This directory exists for one reason: so that **"verified" means "executed"**. It runs the
project's PHP on a WebAssembly PHP runtime, which allows linting, running and testing the
application in environments where no native PHP is installed.

> The product does **not** depend on anything here. On the target host it runs on native PHP and a
> database only - no Node, no Composer, no build step (ADR-0002). If this directory disappeared, the
> product would still install and run.

## Setup

```bash
cd tools/dev
npm install          # installs @php-wasm/node only
```

`node_modules/` is not committed.

## Commands

From the repository root:

```bash
# Parse-check every PHP file in the repository (fails on a real syntax error)
node tools/dev/php.mjs lint
node tools/dev/php.mjs lint app/Core/Router.php

# Run the test suite (tests/run.php)
node tools/dev/php.mjs test
node tools/dev/php.mjs test --filter=Validator

# Run a single PHP file
node tools/dev/php.mjs run bin/smoke.php
node tools/dev/php.mjs run bin/smoke.php --config=/path/to/config.php
```

On a machine or host that has native PHP, the same things are run directly:

```bash
php tools/dev/lint-files.php "$(printf '["%s"]' "$(pwd)/app/Core/Router.php")"   # parse-check
php tests/run.php                                                                # test suite
php bin/smoke.php --config=/path/to/config.php                                   # installation self-check
```

## Notes and limits

- The runtime is PHP **8.4** with `mbstring`, `pdo_sqlite`, `pdo_mysql` (driver only), `curl`,
  `openssl`, `zip`, `gd`, `fileinfo`, `session`, `tokenizer`, `dom`, `filter`, `hash`. `intl` is
  **not** available here, so do not introduce a dependency on it without checking the host first.
- WebAssembly has no web server: `bin/smoke.php` includes the real front controller with
  superglobals set, which is the closest equivalent to an HTTP request available here. Once the
  product is on the host, the same script answers the same question over real HTTP semantics.
- A check that cannot fail is not a check: `tools/dev/lint-files.php` was verified against a
  deliberately broken file, and it reported the parse error (see the Phase 0 report).
