/**
 * Browser harness cases - the shared suite.
 *
 * This file is executed in TWO environments, and that is the point:
 *
 *   1. in a real browser at /tests/browser (development only), where the owner runs it, and
 *   2. in Node with jsdom (`node tools/dev/browser-tests.mjs`), so the suite can be executed
 *      during development and its result recorded, even where no browser is installed.
 *
 * So it must not use ES modules, `import`, bundler syntax or anything a browser of 2020 cannot
 * parse - `C-3` allows vanilla JavaScript only, and the suite tests that constraint by living in it.
 * The only globals it touches are the ones it publishes: `window.chapinoBrowserTests`.
 *
 * A case receives one context object:
 *
 *     { window, document, chapino, assert, fixture }
 *
 * `fixture(name)` clones the markup with `data-fixture="name"` from the fixture template, attaches it
 * to the harness stage and returns the element, so a case always starts from the same DOM and the
 * runner removes it afterwards.
 */
(function (scope) {
  "use strict";

  var cases = [];

  function test(name, run) {
    cases.push({ name: name, run: run });
  }

  /* ---------------------------------------------------------------- digits */

  test("ارقام لاتین به فارسی تبدیل می‌شوند و بقیه‌ی متن دست‌نخورده می‌ماند", function (ctx) {
    ctx.assert.equal(ctx.chapino.toPersianDigits("123"), "۱۲۳");
    ctx.assert.equal(ctx.chapino.toPersianDigits("سفارش 42"), "سفارش ۴۲");
    ctx.assert.equal(ctx.chapino.toPersianDigits("۹۹"), "۹۹", "ارقام فارسی نباید دوباره تبدیل شوند");
    ctx.assert.equal(ctx.chapino.toPersianDigits(""), "");
    ctx.assert.equal(ctx.chapino.toPersianDigits(7), "۷", "عدد خام هم باید کار کند");
  });

  test("ورودی فارسی، عربی و جداکننده‌ها به ارقام لاتین برمی‌گردند", function (ctx) {
    ctx.assert.equal(ctx.chapino.toLatinDigits("۱۲۳"), "123");
    ctx.assert.equal(ctx.chapino.toLatinDigits("٤٥"), "45", "ارقام عربی-هندی هم باید خوانده شوند");
    ctx.assert.equal(ctx.chapino.toLatinDigits("۱٬۲۳۴"), "1234", "جداکننده‌ی هزارگان فارسی حذف می‌شود");
    ctx.assert.equal(ctx.chapino.toLatinDigits("۱۲٫۵"), "12.5", "ممیز فارسی نقطه می‌شود");
    ctx.assert.equal(ctx.chapino.toLatinDigits("−۱۲"), "-12", "منهای راست‌چین باید به منهای لاتین برگردد");
  });

  test("قالب‌بندی عدد صحیح: جداکننده‌ی فارسی، ارقام فارسی و علامت منفی درست", function (ctx) {
    ctx.assert.equal(ctx.chapino.formatInteger(0), "۰");
    ctx.assert.equal(ctx.chapino.formatInteger(1234), "۱٬۲۳۴");
    ctx.assert.equal(ctx.chapino.formatInteger(1000000), "۱٬۰۰۰٬۰۰۰");
    ctx.assert.equal(ctx.chapino.formatInteger(-2500), "−۲٬۵۰۰");
    ctx.assert.equal(ctx.chapino.formatInteger("45000"), "۴۵٬۰۰۰", "رشته‌ی عددی هم پذیرفته می‌شود");
  });

  test("عدد اعشاری یا نامعتبر به formatInteger داده شود، خطا می‌دهد نه عدد حدسی", function (ctx) {
    ctx.assert.throws(function () {
      ctx.chapino.formatInteger(12.5);
    }, "TypeError", "گرد کردن پول بدون اجازه ممنوع است");

    ctx.assert.throws(function () {
      ctx.chapino.formatInteger("نامعلوم");
    }, "TypeError", "متن نامعتبر نباید به صفر تبدیل شود");
  });

  test("قالب‌بندی مبلغ با رقم اعشار و ممیز فارسی", function (ctx) {
    ctx.assert.equal(ctx.chapino.formatAmount(1234), "۱٬۲۳۴");
    ctx.assert.equal(ctx.chapino.formatAmount(1234.567, 2), "۱٬۲۳۴٫۵۷");
    ctx.assert.equal(ctx.chapino.formatAmount(12.5), "۱۳", "بدون رقم اعشار، گرد می‌شود");
    ctx.assert.equal(ctx.chapino.formatAmount(-1234.5, 1), "−۱٬۲۳۴٫۵");
  });

  /* ------------------------------------------------------------- DOM values */

  test("localizeNumbers فقط عناصر نشان‌دار را تغییر می‌دهد و عدد بی‌نشان را رها می‌کند", function (ctx) {
    ctx.fixture("number-attribute");
    var unmarked = ctx.fixture("number-unmarked");

    ctx.chapino.localizeNumbers(ctx.document);

    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-attribute"]').textContent,
      "۴۵٬۰۰۰",
      "مقدار از خود نشانه خوانده می‌شود، نه از متن",
    );
    ctx.assert.equal(unmarked.textContent, "1234567", "بدون نشانه، هیچ چیز نباید تغییر کند");
  });

  test("localizeNumbers مقدار متنی، اعشار و مقدار نامعتبر را درست مدیریت می‌کند", function (ctx) {
    ctx.fixture("number-text");
    ctx.fixture("number-fraction");
    ctx.fixture("number-invalid");
    ctx.fixture("number-fractional-no-declaration");

    ctx.chapino.localizeNumbers(ctx.document);

    ctx.assert.equal(ctx.document.querySelector('[data-fixture="number-text"]').textContent, "۱٬۲۳۴");
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-fraction"]').textContent,
      "۱٬۲۳۴٫۵۷",
      "با اعلام رقم اعشار، ممیز نمایش داده می‌شود",
    );
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-invalid"]').textContent,
      "مقدار نامعلوم",
      "مقدار ناخوانا باید همان‌طور که سرور نوشته باقی بماند؛ نه NaN، نه خطا",
    );
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-fractional-no-declaration"]').textContent,
      "12.5",
      "عدد اعشاری بدون اعلام اعشار نباید بی‌صدا گرد شود",
    );
  });

  test("عدد منفی روی صفحه با منهای درست و جداکننده‌ی فارسی نوشته می‌شود", function (ctx) {
    ctx.fixture("number-negative");

    ctx.chapino.localizeNumbers(ctx.document);

    // The minus is U+2212, not the hyphen: it is the character that renders correctly next to Persian
    // digits in a right-to-left line, and the one the RTL pass must not move to the wrong side.
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-negative"]').textContent,
      "\u2212۲٬۵۰۰",
      "عدد منفی باید با علامت منهای درست و ارقام فارسی نوشته شود"
    );
  });

  test("مقدار فارسی روی صفحه، در اجرای بعدی هم قالب‌بندی می‌شود (نه فقط دست‌نخورده)", function (ctx) {
    ctx.fixture("number-persian-text");
    ctx.fixture("number-arabic-attribute");

    ctx.chapino.localizeNumbers(ctx.document);

    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-persian-text"]').textContent,
      "۱٬۲۳۴",
      "عدد فارسیِ بدون جداکننده باید جداکننده بگیرد"
    );
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-arabic-attribute"]').textContent,
      "۱٬۲۳۴",
      "ارقام عربی باید به ارقام فارسی تبدیل شوند"
    );
  });

  test("اجرای دوباره‌ی localizeNumbers نتیجه را تغییر نمی‌دهد (برای صفحه‌های پویا)", function (ctx) {
    ctx.fixture("number-attribute");
    ctx.fixture("number-text");
    ctx.fixture("number-fraction");

    ctx.chapino.localizeNumbers(ctx.document);
    var first = {
      attribute: ctx.document.querySelector('[data-fixture="number-attribute"]').textContent,
      text: ctx.document.querySelector('[data-fixture="number-text"]').textContent,
      fraction: ctx.document.querySelector('[data-fixture="number-fraction"]').textContent
    };

    // The design studio re-runs this after every change to the document, so the second pass must be
    // a no-op. It reads values that are already Persian, with U+066C separators, and it must not
    // print NaN or throw on them.
    ctx.chapino.localizeNumbers(ctx.document);

    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-attribute"]').textContent,
      first.attribute,
      "اجرای دوم روی مقدار نشانه‌دار",
    );
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-text"]').textContent,
      first.text,
      "اجرای دوم روی مقدار متنی (که اکنون فارسی است)",
    );
    ctx.assert.equal(
      ctx.document.querySelector('[data-fixture="number-fraction"]').textContent,
      first.fraction,
      "اجرای دوم روی مقدار اعشاری",
    );
  });

  /* ------------------------------------------------------------------ forms */

  test("توکن CSRF از متاتگ خوانده می‌شود", function (ctx) {
    var meta = ctx.document.querySelector('meta[name="csrf-token"]');

    ctx.assert.true(meta !== null, "صفحه‌ی هارنس باید متاتگ توکن داشته باشد");
    ctx.assert.equal(ctx.chapino.csrfToken(), meta.getAttribute("content"), "مقدار توکن باید همان متاتگ باشد");
    ctx.assert.true(ctx.chapino.csrfToken().length > 0, "توکن نباید خالی باشد");
  });

  test("توکن به فرم‌های POST اضافه می‌شود و به فرم GET اضافه نمی‌شود", function (ctx) {
    var post = ctx.fixture("form-post");
    var get = ctx.fixture("form-get");
    var withToken = ctx.fixture("form-with-token");

    ctx.chapino.attachCsrfTokens(ctx.document);

    var injected = post.querySelector('input[name="_token"]');
    ctx.assert.true(injected !== null, "فرم POST باید توکن بگیرد");
    ctx.assert.equal(injected.type, "hidden", "فیلد توکن باید پنهان باشد");
    ctx.assert.equal(injected.value, ctx.chapino.csrfToken(), "مقدار توکن باید از متاتگ باشد");

    ctx.assert.true(
      get.querySelector('input[name="_token"]') === null,
      "فرم GET نباید توکن بگیرد؛ توکن در آدرس‌ها لو می‌رود",
    );

    var existing = withToken.querySelectorAll('input[name="_token"]');
    ctx.assert.equal(existing.length, 1, "توکن موجود نباید دوباره اضافه شود");
    ctx.assert.equal(existing[0].value, "token-from-the-server", "توکن موجود نباید بازنویسی شود");
  });

  test("فرم در حال ارسال، دکمه را قفل می‌کند و پیام مشغول بودن می‌گذارد", function (ctx) {
    var form = ctx.fixture("form-post");
    var button = form.querySelector('button[type="submit"]');

    form.dispatchEvent(new ctx.window.Event("submit", { bubbles: true, cancelable: true }));

    ctx.assert.true(button.disabled, "دکمه باید غیرفعال شود تا ارسال دوباره رخ ندهد");
    ctx.assert.equal(button.getAttribute("aria-busy"), "true", "وضعیت مشغول باید برای صفحه‌خوان اعلام شود");
    ctx.assert.true(button.classList.contains("is-busy"), "کلاس is-busy برای استایل دادن لازم است");
    ctx.assert.true(button.querySelector(".spinner") !== null, "اسپینر باید به دکمه اضافه شود");

    // A second submit (impatient double click) must not stack a second spinner.
    form.dispatchEvent(new ctx.window.Event("submit", { bubbles: true, cancelable: true }));
    ctx.assert.equal(button.querySelectorAll(".spinner").length, 1, "قفل دوباره نباید اسپینر دوم بسازد");
  });

  test("فرم با data-no-lock قفل نمی‌شود", function (ctx) {
    var form = ctx.fixture("form-no-lock");
    var button = form.querySelector('button[type="submit"]');

    form.dispatchEvent(new ctx.window.Event("submit", { bubbles: true, cancelable: true }));

    ctx.assert.true(!button.disabled, "خروجی گرفتن از قفل باید محترم شمرده شود");
    ctx.assert.true(button.querySelector(".spinner") === null, "و اسپینری اضافه نشود");
  });

  scope.chapinoBrowserTests = { cases: cases };
})(typeof window !== "undefined" ? window : globalThis);
