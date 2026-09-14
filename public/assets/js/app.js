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
  var THOUSANDS_SEPARATOR = "٬"; // U+066C, the Persian thousands separator
  var DECIMAL_SEPARATOR = "٫"; // U+066B, the Persian decimal separator

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
   * Formats an integer with the Persian thousands separator and Persian digits.
   * Throws on non-integer input rather than guessing: silently rounding money
   * because a caller passed a float is the kind of bug nobody notices until a
   * customer does. (Money is stored as an integer amount for the same reason.)
   */
  function formatInteger(value) {
    var number = typeof value === "number" ? value : Number(value);

    if (!isFinite(number) || Math.floor(number) !== number) {
      throw new TypeError("formatInteger expects a whole number, received: " + String(value));
    }

    var sign = number < 0 ? "−" : ""; // U+2212 minus, not a hyphen: it aligns in RTL
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
    var number = typeof value === "number" ? value : Number(value);

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

    return (negative ? "−" : "") + toPersianDigits(grouped) +
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
      var source = element.getAttribute("data-persian-number") || element.textContent;

      if (element.hasAttribute("data-persian-number-fraction")) {
        var fraction = Number(element.getAttribute("data-persian-number-fraction")) || 0;
        element.textContent = formatAmount(source, fraction);
        return;
      }

      element.textContent = formatInteger(source);
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
    formatInteger: formatInteger,
    formatAmount: formatAmount,
    localizeNumbers: localizeNumbers,
    csrfToken: csrfToken,
  };
})();
