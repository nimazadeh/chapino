/**
 * Base browser script.
 *
 * Everything here is progressive enhancement: the server renders working HTML,
 * and this file makes it nicer. If it fails to load, no page becomes unusable -
 * which is the requirement on a slow mobile connection in Iran (frontend rule,
 * "the frontend must not assume that the backend always succeeds").
 *
 * No framework, no build step, no dependency: this file is served as written
 * (C-3). One global is exposed - `window.chapino` - and it holds only what later
 * phases are allowed to use. Everything else stays inside the closure, because
 * global namespace pollution is how two features start fighting over a variable.
 */
(function () {
  "use strict";

  /* ---------------------------------------------------------------------- *
   * Persian numerals
   *
   * The localization rule splits the two worlds cleanly: what the user READS is
   * Persian, what the system STORES and TRANSPORTS is Latin. So the DOM keeps
   * its Latin digits (and therefore its copy/paste, its sorting and its API
   * payloads), and this formatter is what turns them into Persian digits at the
   * moment of display.
   * ---------------------------------------------------------------------- */

  var PERSIAN_DIGITS = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
  // Arabic-Indic digits (U+0660..U+0669). They are not Persian, but they arrive from Arabic
  // keyboards and copy-pasted text, and a number that silently fails to parse for that reason is
  // indistinguishable, to the user, from the product being broken.
  var ARABIC_INDIC_DIGITS = ["٠", "١", "٢", "٣", "٤", "٥", "٦", "٧", "٨", "٩"];
  var THOUSANDS_SEPARATOR = "٬"; // U+066C, the Persian thousands separator
  var DECIMAL_SEPARATOR = "٫"; // U+066B, the Persian decimal separator
  var MINUS = "−"; // U+2212, not a hyphen: it aligns in RTL

  /**
   * Converts Latin digits in a string to Persian digits.
   * Everything else (letters, signs, separators) is left untouched on purpose:
   * a blind replacement over a whole page would also rewrite the contents of
   * inputs and identifiers, where Latin digits are required.
   */
  function toPersianDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return PERSIAN_DIGITS[Number(digit)];
    });
  }

  /**
   * Turns a string that a HUMAN may have typed or read back into Latin digits.
   *
   * This is the inverse of `toPersianDigits`, and it exists for the same reason: one rule, one
   * implementation. It handles Persian digits, Arabic-Indic digits, both Persian separators and the
   * RTL minus sign, because a value that has already been displayed once (U+066C separators and all)
   * must still be readable when a later update formats it again.
   */
  function toLatinDigits(value) {
    return String(value)
      .replace(/[۰-۹]/g, function (digit) {
        return String(PERSIAN_DIGITS.indexOf(digit));
      })
      .replace(/[٠-٩]/g, function (digit) {
        return String(ARABIC_INDIC_DIGITS.indexOf(digit));
      })
      .replace(/[٬,\s]/g, "") // U+066C and the Latin thousands separator
      .replace(/٫/g, ".") // U+066B decimal separator
      .replace(/−/g, "-"); // U+2212 minus
  }

  /** Reads a number from Latin OR Persian text; NaN when there is no number there. */
  function parseNumber(value) {
    var text = toLatinDigits(value).trim();
    if (text === "") {
      return NaN;
    }

    return Number(text);
  }

  /**
   * Formats an integer with the Persian thousands separator and Persian digits.
   * Throws on non-integer input rather than guessing: silently rounding money
   * because a caller passed a float is the kind of bug nobody notices until a
   * customer does. (Money is stored as an integer amount for the same reason.)
   */
  function formatInteger(value) {
    var number = typeof value === "number" ? value : parseNumber(value);

    if (!isFinite(number) || Math.floor(number) !== number) {
      throw new TypeError("formatInteger expects a whole number, received: " + String(value));
    }

    var sign = number < 0 ? MINUS : "";
    var digits = Math.abs(number).toString();
    var grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS_SEPARATOR);

    return sign + toPersianDigits(grouped);
  }

  /**
   * Formats an amount given in the smallest unit (rial has no decimals in
   * practice, but the function accepts an optional fraction so it does not have
   * to be rewritten if the currency is ratified differently).
   */
  function formatAmount(value, fractionDigits) {
    var digits = fractionDigits || 0;
    var number = typeof value === "number" ? value : parseNumber(value);

    if (!isFinite(number)) {
      throw new TypeError("formatAmount expects a number, received: " + String(value));
    }

    var scaled = Math.round(number * Math.pow(10, digits));
    var text = String(scaled);
    var negative = text.charAt(0) === "-";
    if (negative) {
      text = text.slice(1);
    }

    while (text.length <= digits) {
      text = "0" + text;
    }

    var whole = digits > 0 ? text.slice(0, text.length - digits) : text;
    var fraction = digits > 0 ? text.slice(text.length - digits) : "";
    var grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS_SEPARATOR);

    return (negative ? MINUS : "") + toPersianDigits(grouped) +
      (fraction ? DECIMAL_SEPARATOR + toPersianDigits(fraction) : "");
  }

  /**
   * Turns every element marked with `data-persian-number` into Persian digits.
   * The marker is explicit because the decision "this number is read by a human"
   * is a product decision, not something a script may guess from the markup.
   */
  function localizeNumbers(root) {
    var scope = root || document;
    var elements = scope.querySelectorAll("[data-persian-number]");

    Array.prototype.forEach.call(elements, function (element) {
      var attribute = element.hasAttribute("data-persian-number")
        ? element.getAttribute("data-persian-number")
        : null;
      var source = attribute === null || String(attribute).trim() === ""
        ? element.textContent
        : attribute;
      var number = parseNumber(source);

      // Three failures this function must NOT have, all of them seen in the wild:
      //   * printing "NaN" - the user reads a bug in place of a number;
      //   * throwing - one bad element would stop the whole page's update, and dynamic screens
      //     (the design studio) call this after every change;
      //   * not being re-runnable - the studio re-runs it on updated markup, so a value that has
      //     already been displayed (with U+066C separators) must format to the same thing again.
      // The answer to all three: unreadable input leaves the element exactly as the server wrote it.
      if (!isFinite(number)) {
        return;
      }

      if (element.hasAttribute("data-persian-number-fraction")) {
        var fraction = Number(toLatinDigits(element.getAttribute("data-persian-number-fraction")));
        element.textContent = formatAmount(number, isFinite(fraction) ? fraction : 0);
        return;
      }

      // A fractional value without a declared fraction is not rounded here: silently turning 12.5
      // into 13 is exactly the money bug `formatInteger` refuses to make for its direct callers.
      if (Math.floor(number) !== number) {
        return;
      }

      element.textContent = formatInteger(number);
    });
  }

  /* ---------------------------------------------------------------------- *
   * Forms
   *
   * Two enhancements, both about not losing the user's work:
   *   1. the CSRF token is attached to every form (and to fetches) from one
   *      place, so no template can forget it;
   *   2. a submitting form disables its submit button and reports `aria-busy`,
   *      which is what stops a double submission on a slow connection.
   * ---------------------------------------------------------------------- */

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }

  function attachCsrfTokens(root) {
    var token = csrfToken();
    if (!token) {
      return;
    }

    var forms = (root || document).querySelectorAll("form");

    Array.prototype.forEach.call(forms, function (form) {
      var method = (form.getAttribute("method") || "get").toLowerCase();
      if (method === "get") {
        return;
      }

      if (form.querySelector('input[name="_token"]')) {
        return;
      }

      var field = document.createElement("input");
      field.type = "hidden";
      field.name = "_token";
      field.value = token;
      form.appendChild(field);
    });
  }

  function lockSubmittingForm(form) {
    var button = form.querySelector('button[type="submit"], button:not([type])');
    if (!button || button.disabled) {
      return;
    }

    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.classList.add("is-busy");

    // The spinner is added as markup, not as a background image, so it keeps
    // working at any zoom level and remains visible in High Contrast mode.
    var spinner = document.createElement("span");
    spinner.className = "spinner";
    spinner.setAttribute("aria-hidden", "true");
    button.insertBefore(spinner, button.firstChild);
  }

  function initForms(root) {
    attachCsrfTokens(root);

    document.addEventListener("submit", function (event) {
      var form = event.target;
      if (!form || form.nodeName !== "FORM" || form.hasAttribute("data-no-lock")) {
        return;
      }

      lockSubmittingForm(form);
    });
  }

  /* ---------------------------------------------------------------------- *
   * Bootstrap
   * ---------------------------------------------------------------------- */

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback);
    } else {
      callback();
    }
  }

  ready(function () {
    localizeNumbers(document);
    initForms(document);
  });

  window.chapino = {
    // Display helpers. Later phases use these instead of writing their own,
    // so Persian numerals stay consistent across the product.
    toPersianDigits: toPersianDigits,
    toLatinDigits: toLatinDigits,
    formatInteger: formatInteger,
    formatAmount: formatAmount,
    localizeNumbers: localizeNumbers,
    csrfToken: csrfToken,
    // Exposed because dynamically added forms need the token too: the studio adds forms after
    // load, and a form that submits without the token is rejected by the server.
    attachCsrfTokens: attachCsrfTokens,
  };
})();
