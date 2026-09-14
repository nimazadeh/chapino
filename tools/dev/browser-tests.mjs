#!/usr/bin/env node
/**
 * Development-only browser-harness runner.
 *
 * Runs the SAME files the browser page runs - `public/assets/js/app.js`, `tests/browser/cases.js` and
 * `tests/browser/harness.js` - inside jsdom, so the harness can be executed and its result recorded
 * where no browser is installed (which includes the environment this project is developed in).
 *
 * What this proves and what it does not:
 *   * proves the assertions pass against a real DOM implementation (jsdom) and the real base script;
 *   * does NOT prove layout, rendering, RTL shaping, fonts or anything visual - jsdom has no layout
 *     engine. That is why `/tests/browser` still exists and why the owner runs it in a real browser.
 *
 * jsdom is a development dependency only: the product never loads it, never needs Node, and runs on
 * native PHP (ADR-0002).
 *
 * Usage:  node tools/dev/browser-tests.mjs
 * Exit:   0 every case passed, 1 at least one failed, 2 the harness could not be set up.
 */
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const BROWSER_DIR = join(ROOT, 'tests', 'browser');

function fail(message) {
  process.stderr.write(`\n${message}\n\n`);
  process.exit(2);
}

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch {
  fail(
    'jsdom is not installed.\n' +
      'Run:  cd tools/dev && npm install\n' +
      '(Development convenience only - the product itself runs in a browser and on native PHP.)',
  );
}

function read(path, label) {
  try {
    return readFileSync(path, 'utf8');
  } catch {
    fail(`Could not read ${label}: ${path}`);
  }
}

const fixtures = read(BROWSER_DIR + '/fixtures.html', 'the fixture template');
const cases = read(BROWSER_DIR + '/cases.js', 'the shared cases');
const harness = read(BROWSER_DIR + '/harness.js', 'the harness runner');
const appScript = read(join(ROOT, 'public', 'assets', 'js', 'app.js'), 'the base script');

// The smallest page that can host the harness: the elements harness.js reports into, the fixture
// template, and a CSRF meta tag - which is what the real page gets from the session.
const page = `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="csrf-token" content="token-from-the-node-harness">
<title>تست مرورگر (jsdom)</title>
</head>
<body>
<div id="harness-summary" class="alert"></div>
<button type="button" id="harness-rerun"></button>
<ul id="harness-report"></ul>
${fixtures}
</body>
</html>`;

const dom = new JSDOM(page, { runScripts: 'outside-only', url: 'http://localhost/tests/browser' });
const { window } = dom;

try {
  // The base script attaches its listeners on DOMContentLoaded when the document is still parsing,
  // exactly as it does in a browser. Waiting for that event here is not a workaround: a browser runs
  // deferred scripts after the document is parsed, and running the cases before that would test a
  // page state that never exists for a user.
  window.eval(appScript);
  await new Promise((resolve) => {
    if (window.document.readyState === 'complete') {
      resolve();
      return;
    }
    window.addEventListener('load', resolve);
  });

  window.eval(cases);
  window.eval(harness);
} catch (error) {
  fail(`The harness itself failed to execute: ${(error && error.stack) || error}`);
}

const result = window.harnessResult;
if (!result) {
  fail('The harness produced no result object (window.harnessResult is missing).');
}

process.stdout.write('تست مرورگر چاپینو — اجرا در jsdom (بدون موتور چیدمان)\n');
process.stdout.write('='.repeat(72) + '\n');
for (const failure of result.failures) {
  process.stdout.write(`  FAIL  ${failure.name}\n        ${failure.failure}\n`);
}
process.stdout.write('='.repeat(72) + '\n');
process.stdout.write(
  `Tests: ${result.passed} passed, ${result.failed} failed, ${result.total} total (jsdom)\n`,
);
process.stdout.write(
  'بازبینی چشمی و رفتار واقعی مرورگر: /tests/browser را در مرورگر باز کنید (فقط محیط توسعه).\n',
);

process.exit(result.failed === 0 ? 0 : 1);
