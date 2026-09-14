/**
 * Browser harness runner.
 *
 * Runs the shared case file (tests/browser/cases.js) inside a real page and reports the result in
 * the page itself, in Persian, because the person running it is the product owner, not a test
 * framework. It also publishes `window.harnessResult` so the result can be read from the console or
 * copied out without reading the table.
 *
 * It deliberately contains NO product logic: every behaviour under test lives in
 * `public/assets/js/app.js`, and every expectation lives in `cases.js`. The same two files are
 * executed by `tools/dev/browser-tests.mjs` under jsdom, so this runner and that runner must not
 * become the only place a behaviour is described.
 */
(function (scope) {
  "use strict";

  var document = scope.document;

  function AssertionError(message) {
    this.name = "AssertionError";
    this.message = message;
  }
  AssertionError.prototype = Object.create(Error.prototype);
  AssertionError.prototype.constructor = AssertionError;

  function describe(value) {
    if (typeof value === "string") {
      return '"' + value + '"';
    }
    if (value === null) {
      return "null";
    }
    if (typeof value === "object") {
      return Object.prototype.toString.call(value);
    }

    return String(value);
  }

  function createAssert() {
    var assert = {};

    assert.equal = function (actual, expected, message) {
      if (actual !== expected) {
        throw new AssertionError(
          (message ? message + " — " : "") +
            "انتظار " + describe(expected) + " بود، " + describe(actual) + " دریافت شد",
        );
      }
    };

    assert.true = function (value, message) {
      if (value !== true) {
        throw new AssertionError((message ? message + " — " : "") + "انتظار مقدار درست بود، " + describe(value) + " دریافت شد");
      }
    };

    assert.throws = function (callback, expectedName, message) {
      try {
        callback();
      } catch (error) {
        if (expectedName && error && error.name !== expectedName) {
          throw new AssertionError(
            (message ? message + " — " : "") +
              "انتظار خطای " + expectedName + " بود، " + (error && error.name) + " دریافت شد",
          );
        }

        return;
      }

      throw new AssertionError((message ? message + " — " : "") + "انتظار خطا بود، اما هیچ خطایی رخ نداد");
    };

    return assert;
  }

  function stage() {
    var existing = document.getElementById("harness-stage");
    if (existing) {
      return existing;
    }

    var element = document.createElement("div");
    element.id = "harness-stage";
    element.setAttribute("hidden", "hidden");
    document.body.appendChild(element);

    return element;
  }

  function fixture(name) {
    var template = document.getElementById("fixtures");
    if (!template) {
      throw new Error("قالب فیکسچرها (#fixtures) در صفحه نیست");
    }

    var fragment = document.importNode(template.content, true);
    var element = fragment.querySelector('[data-fixture="' + name + '"]');
    if (!element) {
      throw new Error("فیکسچر ناشناخته: " + name);
    }

    stage().appendChild(element);

    return element;
  }

  function clearStage() {
    var element = document.getElementById("harness-stage");
    if (element) {
      element.innerHTML = "";
    }
  }

  function run(cases) {
    var results = [];

    cases.forEach(function (testCase) {
      var failure = null;

      try {
        testCase.run({
          window: scope,
          document: document,
          chapino: scope.chapino,
          assert: createAssert(),
          fixture: fixture,
        });
      } catch (error) {
        failure = (error && error.message) || String(error);
      } finally {
        clearStage();
      }

      results.push({ name: testCase.name, passed: failure === null, failure: failure });
    });

    return results;
  }

  function report(results) {
    var target = document.getElementById("harness-report");
    var summary = document.getElementById("harness-summary");
    var passed = results.filter(function (result) {
      return result.passed;
    }).length;
    var failed = results.length - passed;

    if (!target || !summary) {
      return;
    }

    target.innerHTML = "";

    results.forEach(function (result) {
      var row = document.createElement("li");
      row.className = "harness-case " + (result.passed ? "is-pass" : "is-fail");
      row.setAttribute("data-status", result.passed ? "pass" : "fail");

      var name = document.createElement("span");
      name.className = "harness-case-name";
      name.textContent = (result.passed ? "✅ " : "❌ ") + result.name;
      row.appendChild(name);

      if (!result.passed) {
        var failure = document.createElement("p");
        failure.className = "harness-case-failure";
        failure.textContent = result.failure;
        row.appendChild(failure);
      }

      target.appendChild(row);
    });

    summary.setAttribute("data-passed", String(passed));
    summary.setAttribute("data-failed", String(failed));
    summary.className = "alert " + (failed === 0 ? "alert-success" : "alert-danger");
    summary.textContent = failed === 0
      ? "همه‌ی " + passed + " بررسی سبز است."
      : failed + " بررسی از " + results.length + " ناموفق است.";

    document.title = (failed === 0 ? "✅ " : "❌ ") + passed + "/" + results.length + " — تست مرورگر چاپینو";

    scope.harnessResult = {
      passed: passed,
      failed: failed,
      total: results.length,
      failures: results.filter(function (result) {
        return !result.passed;
      }),
    };
  }

  function fail(message) {
    var summary = document.getElementById("harness-summary");
    if (summary) {
      summary.className = "alert alert-danger";
      summary.textContent = message;
    }

    scope.harnessResult = { passed: 0, failed: 1, total: 1, failures: [{ name: "راه‌اندازی", failure: message }] };
  }

  if (!scope.chapino) {
    fail("chapino (public/assets/js/app.js) بارگذاری نشد؛ هارنس نمی‌تواند چیزی را بررسی کند.");
    return;
  }

  if (!scope.chapinoBrowserTests || !scope.chapinoBrowserTests.cases) {
    fail("فایل موارد تست (tests/browser/cases.js) بارگذاری نشد.");
    return;
  }

  var rerun = document.getElementById("harness-rerun");
  if (rerun) {
    rerun.addEventListener("click", function () {
      report(run(scope.chapinoBrowserTests.cases));
    });
  }

  report(run(scope.chapinoBrowserTests.cases));
})(typeof window !== "undefined" ? window : globalThis);
