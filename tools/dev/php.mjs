#!/usr/bin/env node
/**
 * Development-only PHP runner (see tools/dev/README.md).
 *
 * Runs the project's PHP on a WebAssembly PHP runtime so that code can be linted,
 * executed and tested even where no native PHP is installed, which makes "verified"
 * mean "executed" instead of "read carefully".
 *
 * The product NEVER depends on this tool or on Node: on the target host it runs on
 * native PHP only (ADR-0002).
 *
 * Usage
 *   node tools/dev/php.mjs lint [path...]        parse-check PHP files (default: whole repo)
 *   node tools/dev/php.mjs test [--filter=Text]  run the PHP test suite
 *   node tools/dev/php.mjs run <file.php> [args] run a single PHP file
 *   node tools/dev/php.mjs check                 lint, then test
 *
 * Setup (once):  cd tools/dev && npm install
 */
import { existsSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = fileURLToPath(new URL('.', import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const PHP_VERSION = '8.4';
const EXCLUDED_DIRS = new Set([
  '.git', 'node_modules', 'tools', 'storage', 'docs', '.agents', 'vendor', '.devtools',
]);

const SELF = 'tools/dev/php.mjs';

function fail(message) {
  process.stderr.write(`\n${message}\n\n`);
  process.exit(1);
}

async function bootPhp() {
  let PHP, loadNodeRuntime, useHostFilesystem;
  try {
    ({ PHP } = await import('@php-wasm/universal'));
    ({ loadNodeRuntime, useHostFilesystem } = await import('@php-wasm/node'));
  } catch {
    fail(
      `The development PHP runtime is not installed.\n` +
        `Run:  cd tools/dev && npm install\n` +
        `(Development convenience only - the product itself runs on native PHP.)`,
    );
  }
  const php = new PHP(await loadNodeRuntime(PHP_VERSION, { emscriptenOptions: { processId: 1 } }));
  // Mount the host filesystem so PHP reads the real repository files; no copying, no
  // stale duplicates of the code under test.
  useHostFilesystem(php);
  php.chdir(ROOT);

  return php;
}

function listPhpFiles(paths) {
  const out = [];
  const walk = (dir) => {
    for (const entry of readdirSync(dir).sort()) {
      if (EXCLUDED_DIRS.has(entry)) continue;
      const full = join(dir, entry);
      const st = statSync(full);
      if (st.isDirectory()) walk(full);
      else if (entry.endsWith('.php')) out.push(full);
    }
  };
  for (const p of paths.length ? paths : ['.']) {
    const full = resolve(ROOT, p);
    if (!existsSync(full)) {
      fail(`Path does not exist: ${p}`);
    }
    if (statSync(full).isDirectory()) walk(full);
    else if (full.endsWith('.php')) out.push(full);
  }

  return out;
}

async function runPhpCli(php, argv) {
  const response = await php.cli(argv);
  const stdout = await response.stdoutText;
  const stderr = await response.stderrText;
  const exitCode = await response.exitCode;

  return { stdout, stderr, exitCode };
}

async function lint(paths) {
  const php = await bootPhp();
  const files = listPhpFiles(paths);
  if (files.length === 0) fail('No PHP files found to lint.');

  process.stdout.write(`PHP lint (PHP ${PHP_VERSION} via WebAssembly): ${files.length} file(s)\n`);
  const { stdout, stderr, exitCode } = await runPhpCli(php, [
    'php', join(ROOT, 'tools/dev/lint-files.php'), JSON.stringify(files),
  ]);

  if (stdout) process.stdout.write(stdout);
  if (stderr) process.stderr.write(stderr);
  if (exitCode !== 0) {
    process.stderr.write('PHP lint FAILED.\n');
  } else {
    process.stdout.write('PHP lint passed.\n');
  }

  process.exit(exitCode === 0 ? 0 : 1);
}

async function run([file, ...args]) {
  if (!file) fail(`Usage: node ${SELF} run <file.php> [args...]`);
  const full = resolve(ROOT, file);
  if (!existsSync(full)) fail(`File does not exist: ${file}`);
  const php = await bootPhp();
  const { stdout, stderr, exitCode } = await runPhpCli(php, ['php', full, ...args]);
  if (stdout) process.stdout.write(stdout);
  if (stderr) process.stderr.write(stderr);
  process.exit(exitCode);
}

async function test(args) {
  const php = await bootPhp();
  const { stdout, stderr, exitCode } = await runPhpCli(php, ['php', join(ROOT, 'tests/run.php'), ...args]);
  if (stdout) process.stdout.write(stdout);
  if (stderr) process.stderr.write(stderr);
  process.exit(exitCode);
}

const [command, ...rest] = process.argv.slice(2);
switch (command) {
  case 'lint':
    await lint(rest);
    break;
  case 'run':
    await run(rest);
    break;
  case 'test':
    await test(rest);
    break;
  case 'check':
    await lint([]);
    break;
  default:
    fail(`Commands: lint [path...] | test [--filter=..] | run <file.php> | check`);
}
