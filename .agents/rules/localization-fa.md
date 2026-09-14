# Rule: Persian (fa-IR) Localization and RTL

**Rule ID:** `localization`
**Applies to:** every user-visible string, date, number, currency, name, form, layout, asset or
message.
**Canonical owner of:** language, direction, Persian typography, calendar and numeral conventions,
input normalization, locale-specific UX.
**See also:** [`frontend`](frontend.md), [`database`](database.md), [`security`](security.md), [`qa`](qa.md).

---

## 1. Scope (owner-stated constraint)

The product is used **inside Iran**, and the user interface is **Persian only**. There is no
multi-language requirement. Do not build an i18n framework, translation catalogue or language
switcher unless the owner later asks for one; do build code that does not make Persian a hardcoded
afterthought.

Rules:

- No English (or any other language) user-visible string in the product - including buttons, labels,
  placeholders, error messages, empty states, tooltips, alt text, validation messages, emails,
  notifications, confirmations, print output and 404/500 pages. Latin-script text is acceptable only
  for data that is genuinely Latin: email addresses, URLs, codes, model names, brand names.
- No mixed-language fallback text such as "خطا: Error".
- Text is written in Persian, not machine-translated word-for-word. Use the terminology a Persian
  speaker in Iran would expect for the domain; keep terms consistent across the product and record
  the glossary (section 7).
- Numbers shown to users are Persian-formatted (see section 4). Numbers in code, identifiers, API
  payloads, file names and technical logs stay Latin.

## 2. Direction and layout

- The document declares Persian language and right-to-left direction at the document level
  (`lang` and `dir` attributes on the root element).
- The layout is built RTL-first - not mirrored at the end. Spacing, ordering, icons, progress
  direction and animations follow RTL.
- **Bidirectional text is a first-class concern.** Any embedded Latin or numeric content (email,
  URL, code, version, mixed sentence) must render correctly: use the proper isolation mechanisms
  (`dir` attributes on the element containing user or Latin content, or the Unicode isolation
  characters) rather than relying on the browser's guess.
- Punctuation, quotation marks, parentheses and slashes must not visually invert around mixed
  content.
- Direction must never be inferred from the content of a user-supplied string.
- Tables, lists, breadcrumbs, steppers and charts read right-to-left; numeric columns may remain
  left-aligned where that aids comparison, and the choice must be consistent.

## 3. Typography and text

- Use a font that renders Persian properly, with correct shaping and joining; specify a sensible
  fallback chain and do not rely on a CDN the owner has not ratified.
- Use Persian typography conventions: the Persian comma and semicolon, the correct question mark,
  half-space (ZWNJ) where Persian orthography requires it (`میشود`, `کتابها`), and correct
  spelling of common words.
- Never use ZWNJ-free machine output as-is; check the rendered text.
- Line height and letter spacing must suit Persian script; do not reuse Latin-optimized values.
- Do not stretch, condense, all-caps or letter-space Persian text for visual effect.
- Long text must wrap gracefully in RTL without breaking words incorrectly.

## 4. Numerals, dates, time and currency

| Concern | Requirement |
| --- | --- |
| Digits | User-visible digits are Persian (۰۱۲۳۴۵۶۷۸۹), formatted with the Persian thousands separator |
| Storage / transport | Digits in data, identifiers, API payloads and code stay Latin, unformatted |
| Calendar | User-visible dates use the Persian (Jalali) calendar with Persian month names; the Gregorian date may be shown as secondary info when useful |
| Time | Clock time in the Iranian convention (۲۴-hour is the default; state which you use) and the correct timezone (Asia/Tehran) including daylight-saving behaviour |
| Relative time | "۳ روز پیش", "همین حالا" - written correctly, and never computed from a wrong clock assumption |
| Currency | The currency must be ratified by the owner; until then do not assume. Amounts are exact (no floating point), with the unit and separators displayed consistently |
| Percentages, decimals | Use the Persian decimal separator (٫) in the UI |
| Phone numbers | Iranian formats, with the common local prefixes, validated accordingly, stored in one canonical form |
| Postal code / national ID | Iranian formats, validated by their real rules, never by a placeholder rule |
| Ranges and lists | Persian separators and correct RTL ordering |
| File sizes, durations, units | Persian unit names with Persian digits |

Never implement a Jalali conversion by hand-rolled arithmetic; use a proven, reviewed conversion
approach and unit-test it around leap years, month boundaries and the 1979-03-21 style transition
cases.

## 5. Input normalization (critical for Persian text)

Unify user input before storing or comparing:

- Normalize Arabic vs. Persian characters: `ي` → `ی`, `ك` → `ک`, `ة` → `ه` (where appropriate),
  Arabic-Indic digits `٠١٢...` → Persian/Latin digits per your storage convention.
- Remove or normalize diacritics and tatweel (`ـ`) unless the field genuinely needs them.
- Normalize the Persian/Arabic keyboard-mapped characters and half-space variants so that
  "نمیشود" and "نمیشود" compare equal where the user would expect equality.
- Trim, collapse internal whitespace, and normalize line endings.
- **Search** must apply the same normalization the store applies, or users will not find their own
  data. Any search feature must state its normalization and matching strategy.
- Normalization never replaces validation: the canonical form is what gets validated and stored.

## 6. Forms and UX in Persian context

- Labels, hints and error messages in Persian; error messages state the required fix.
- Input direction: text inputs that accept Persian content default to RTL; inputs that accept
  email, URL or code are explicitly LTR with the correct alignment. A single input must not switch
  direction based on typed content.
- Persian keyboard behavior: do not rely on typing Latin digits - accept both Persian and Latin
  digits in numeric fields and normalize them.
- Date pickers present the Jalali calendar; do not force the user to convert mentally.
- Sorting and search over Persian text follow the Persian alphabet order, not byte order - this is an
  explicit requirement to raise with the database decision (see [`database`](database.md)).
- Error, empty and loading states use Persian phrasing that a real user would recognize, not a
  literal translation.

## 7. Consistency assets

Maintain in the repository:

- A **terminology glossary** (Persian term ↔ code concept) once the domain is known, so the same
  concept is never named two ways.
- A **microcopy list** for recurring states (error, retry, empty, confirm, delete, cancel, save), so
  the product speaks with one voice.
- A **number/date formatting helper** used everywhere instead of ad-hoc formatting in templates.

## 8. Checklist

- [ ] No non-Persian user-visible string anywhere (including errors, emails, notifications, alt text).
- [ ] RTL-first layout; no LTR assumption baked into spacing, icons or ordering.
- [ ] Mixed Persian/Latin and numeric content renders correctly (verified visually).
- [ ] ZWNJ, half-space and punctuation correct in the rendered text.
- [ ] Digits: Persian in the UI, Latin in data/transport, with the Persian separator.
- [ ] Dates: Jalali with Persian month names; correct timezone; conversion unit-tested.
- [ ] Currency and phone/postal/national-ID formats follow ratified rules, not invented ones.
- [ ] Input normalization applied before storage and search; search matches normalized text.
- [ ] Inputs have explicit direction per field type; both digit sets accepted.
- [ ] Persian sorting/search order requirement recorded and addressed at the data layer.
- [ ] Terminology and microcopy consistent; no mixed-language message.
