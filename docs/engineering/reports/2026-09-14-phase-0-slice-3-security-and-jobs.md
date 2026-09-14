# Engineering Report - Phase 0 slice 3: sessions, CSRF, rate limiting, job queue and cron

- Date: 2026-09-14
- Workflow: `feature-development`
- Branch: `arena/01a09e2c-chapino`
- Revision verified: `e20471d` + the working tree of this slice (checks ran against the dirty tree; the commit is named in the final section)
- Rules applied: `security`, `backend`, `database`, `qa`, `code-review`, `reporting`, `git-workflow`

> Format and vocabulary: [.agents/rules/reporting.md](../../../.agents/rules/reporting.md).
> Every claim below is backed by an executed command and its observed output.
> Prose is in Persian because the requester writes Persian; keywords, paths and commands stay English.
>
> **Technology status in this report.** Database family is a **provisional, unratified working
> assumption** (`O-2`): MySQL family for the host, SQLite as the local test engine. No host exists yet
> (`O-20`), so every result below is "verified locally, unverified on the target host".

---

## STATUS

`PASS WITH RISKS`

نشست/کوکی، CSRF، limit نوشتن ناشناس و صف کار + کران پیاده‌سازی و **اجرا** شدند، همراه با سیم‌کشی آن‌ها
در مسیر واقعی درخواست (`app/middleware.php`) و ابزارهای اپراتور (`bin/cron.php`, `bin/queue.php`).
همه‌ی تست‌ها روی موتور محلیِ تست (SQLite، فرضی و تصویب‌نشده طبق `O-2`) و روی همان کدی که روی هاست اجرا
می‌شود سبز است. دو ریسک باقی است: شاخه‌ی موتور فرضیِ MySQL-خانواده در محیط توسعه **قابل اجرا نیست** (runtime را از کار می‌اندازد، نه استثنای قابل‌گرفتن) —
فقط با قرارداد تست‌شده پوشش داده شده است — و هیچ اجرایی روی هاست واقعی انجام نشده، چون هاستی وجود
ندارد. همچنین «مهلت هر job» در این مرحله وجود ندارد (ثبت‌شده به‌عنوان محدودیت، نه نقص پنهان).

## IMPLEMENTED

**نشست و کوکی** (`app/Core/Security/Session.php`, `SessionStore.php`, `SessionException.php`):
سیاست کامل کوکی (`HttpOnly`, `SameSite=Lax`, `Secure` روی HTTPS، `use_strict_mode`,
`use_only_cookies`) توسط خود برنامه اعمال می‌شود، نه از `php.ini` ناشناس هاست؛ پوشه‌ی ذخیره‌ی
نشست زیر `storage/` با مجوز `0700` ساخته می‌شود؛ نشست فقط با کوکی یا درخواست تغییردهنده شروع
می‌شود؛ ردیف پایگاه‌داده **فقط** هنگام ورود ساخته می‌شود و `sha256` شناسه را نگه می‌دارد نه خود شناسه؛
ورود شناسه را بازتولید می‌کند (session fixation)؛ خروج ردیف را حذف می‌کند؛ مهلت بی‌کاری و مهلت مطلق از
روی ردیف سرور اعمال می‌شوند؛ حذف ردیف در سرور، دسترسی را در همان درخواست بعدی قطع می‌کند.

**CSRF** (`app/Core/Security/Csrf.php`): توکن ۶۴ کاراکتری در نشست، پذیرش فقط از هدر `X-CSRF-Token`
یا فیلد بدنه‌ی `_token` — **هرگز از query string**؛ بررسی `Origin` در صورت وجود؛ روی همه‌ی متدهای
تغییردهنده به‌صورت deny-by-default، پیش از رسیدن به کنترلر.

**محدودسازی نرخ** (`app/Core/Security/RateLimiter.php`, `RateLimitResult.php`): پنجره‌ی ثابت در
پایگاه‌داده، کلید `sha256(namespace + bucket + key)` (IP به‌صورت متن روشن ذخیره نمی‌شود)، افزایش با
`UPDATE` شرطی و `INSERT` در صورت تعارض تا درخواست‌های هم‌زمان شمارش را گم نکنند؛ پاسخ `429` با
`Retry-After` و لاگ عددی؛ مقدار limit از پیکربندی و `0` = خاموش.

**صف کار و کران** (`app/Core/Jobs/Queue.php`, `JobRunner.php`, `app/Jobs/handlers.php`,
`bin/cron.php`): job = یک ردیف پایگاه‌داده (نوع، payload، attempts، `available_at`، وضعیت،
رزرو، آخرین خطا)؛ تصاحب با `UPDATE ... WHERE status = 'queued'` (اتمیک)؛ retry با backoff نمایی
(`min(3600, 30·2^(attempts−1))`) تا `max_attempts`؛ reclaim کارهای رهاشده پس از انقضا؛ اجرای
بچ‌محدود با `--limit`/`--seconds`، قفل فایل، و علت توقف در خروجی؛ ثبت‌کننده‌ی صریح handler‌ها
(نوع ناشناس = شکست فوری، هرگز «تفسیر» نمی‌شود)؛ هر handler در یک خط توضیح می‌دهد چرا اجرای دوباره
بی‌خطر است.

**ابزار اپراتور**: `bin/cron.php` (فقط CLI؛ `--limit/--seconds/--queue/--status`) و
`bin/queue.php` (شمارش، آخرین job‌ها، نمایش کامل یک job، `--push`, `--retry`).

**سیم‌کشی و یکپارچگی**: `Application` اکنون `isHttps()`, `session()`, `csrf()`, `rateLimiter()`,
`queue()`, `jobs()` را در اختیار می‌گذارد و کانتینر را به middleware می‌دهد؛ ترتیب لایه‌ها:
اندازه‌ی بدنه → نشست → CSRF → limit نوشتن ناشناس. نصب‌کننده پوشه‌ی `storage/sessions` را با `0700`
می‌سازد، و `config/config.example.php` کلیدهای `security.session_*` و `security.rate_limits` را
مستند می‌کند.

**اصلاح‌های ریشه‌ای این برش** (جزئیات در بخش ROOT CAUSES): تفکیک درست خطاهای محدودیت پایگاه‌داده،
پذیرفته‌نشدن توکن CSRF از query string، گارد اندازه‌ی بدنه بر اساس اندازه‌ی واقعی و نه فقط هدر،
تشخیص «اسکیمای ناقص» به‌جای خطای عمومی، حذف پرس‌وجوی پایگاه‌داده برای بازدیدکننده‌ی ناشناس،
علت توقف واقعی در خروجی کران، اعتبارسنجی پارامترهای اتصال موتور فرضیِ MySQL-خانواده، و توقف نشتی
تست‌ها در مخزن.

**عمداً تغییر نکرد:** هیچ فیچر محصولی (طراحی، آپلود، سفارش، پرداخت) ساخته نشد؛ سیستم طراحی RTL
(0.8)، هارنس تست مرورگر (0.9) و رانبوک استقرار (0.10) در دستور کار باقی می‌مانند.

## ROOT CAUSES

1. **`ini_set` روی نشست فعال** — `Session::start()` تنظیمات نشست را همیشه اعمال می‌کرد؛ PHP این کار را
   روی نشست فعال رد می‌کند (`Session ini settings cannot be changed when a session is active`) و
   نتیجه، هشدار روی هر درخواست و سیاست کوکی اعمال‌نشده بود. رفع: تنظیمات فقط زمانی اعمال می‌شوند که
   خودِ برنامه نشست را شروع کند؛ نشست فعالِ از قبل (مثل `session.auto_start` روی هاست) **پذیرفته**
   می‌شود و همین وضعیت با یک warning لاگ می‌شود، چون یعنی هاست پیش از برنامه نشست ساخته است.
2. **یکسان‌بودن همه‌ی خطاهای محدودیت در موتور محلی (SQLite، فرضی/تصویب‌نشده)** — این موتور بدون
extended result codes همه‌ی نقض‌های
   محدودیت را با کد ۱۹ گزارش می‌کند، پس «رکورد تکراری» (رویداد کسب‌وکار) و «نقض کلید خارجی» (باگ) از
   هم قابل تشخیص نبودند؛ نتیجه‌ی واقعی: ثبت نشست برای کاربر ناموجود به‌عنوان «تکراری» بلعیده می‌شد.
   رفع: فعال‌کردن extended result codes و طبقه‌بندی دقیق (`database_duplicate`,
   `database_foreign_key`, `database_constraint`) با تست برای هر سه.
3. **توکن CSRF از query string** — `Csrf` از `Request::input()` می‌خواند که query و بدنه را ادغام
   می‌کند؛ در نتیجه توکن از URL هم پذیرفته می‌شد (افشا در لاگ، history و `Referer`). رفع: دسترسی
   `Request::body()` (فقط بدنه) و استفاده‌ی `Csrf` از آن، با تست اختصاصی.
4. **گارد اندازه‌ی بدنه قابل دور زدن** — فقط `Content-Length` بررسی می‌شد؛ کلاینتی که هدر را حذف کند
   (مثلاً chunked) از limit عبور می‌کرد. رفع: `Request` اندازه‌ی واقعی بدنه را نگه می‌دارد و middleware
   بیشترین مقدار «اعلام‌شده و اندازه‌گیری‌شده» را بررسی می‌کند، با تست مثبت و منفی.
5. **اسکیمای ناقص = خطای عمومی ۵۰۰** — روی نصب تازه‌ای که مهاجرت‌ها اجرا نشده‌اند، جدول‌نبودن
   (`no such table`) به‌صورت «خطای غیرمنتظره» به owner نمایش داده می‌شد، بدون هیچ راهنمایی. رفع:
   طبقه‌بندی `database_schema_missing` → پاسخ `503 setup_required` با دستور دقیق `php bin/migrate.php`.
   هم‌زمان، مسیر نشست برای درخواست‌های ناشناس دیگر به جدول `sessions` دست نمی‌زند، تا «یک دستور فراموش‌شده»
   کل سایت را از کار نیندازد (تست‌شده).
6. **علت توقف نادرست در کران** — اولین اجرای واقعی کران روی نصب تازه، هم‌زمان «۰ کار برداشته شد» و
   «1 job موفق» چاپ می‌کرد، چون علت توقف همیشه `batch_empty` بود. رفع: `batch_exhausted` و
   `job_limit_reached` تفکیک شدند، با تست.
7. **پارامترهای اتصال موتور فرضیِ MySQL-خانواده بدون اعتبارسنجی در DSN** — DSN یک رشته‌ی `;`-جدا است، پس نام پایگاه‌داده‌ی
   حاوی `;` می‌توانست پارامتر اتصال اضافه کند. رفع: `Connection::mysqlDsn()` به‌صورت تابع خالص و
   اعتبارسنجی‌شده (میزبان، نام، charset، پورت) — که هم زمان، شاخه‌ی غیرقابل‌اجرا و تصویب‌نشده‌ی MySQL را
   «تست‌پذیر» کرد.
8. **گارد اتمیک بدون تست** — حذف شرط `status = 'queued'` از `UPDATE` تصاحب job، هیچ تستی را قرمز
   نمی‌کرد (چون تست‌ها ترتیبی بودند و SELECT فیلتر می‌کرد). رفع: استخراج `Queue::claimOne()` به‌عنوان
   پریماِتیو اتمیک و تستی که دو تلاش روی همان ردیف می‌کند و دقیقاً یک موفقیت می‌خواهد. این مورد با
   کنترل جهش تأیید شد (بخش TESTS EXECUTED).
9. **نشتی تست‌ها در مخزن** — دو تست یکپارچه، برنامه‌ی واقعی را با پیکربندی موقت بالا می‌آوردند ولی
   مسیر ذخیره‌ی نشست را تعیین نمی‌کردند، پس فایل نشست داخل `storage/sessions` مخزن ساخته می‌شد.
   رفع: پیکربندی نشست هم داخل پوشه‌ی موقت هر تست. پس از اجرای کامل: `ls -A storage/` فقط `.gitkeep`.

## TESTS EXECUTED

```bash
node tools/dev/php.mjs lint
node tools/dev/php.mjs test
node tools/dev/php.mjs test --filter=SessionTest
node tools/dev/php.mjs test --filter=CsrfTest
node tools/dev/php.mjs test --filter=RateLimiterTest
node tools/dev/php.mjs test --filter=QueueTest
node tools/dev/php.mjs test --filter=SecurityMiddlewareTest
node tools/dev/php.mjs test --filter=FrontControllerSmokeTest
node tools/dev/php.mjs test --filter=DatabaseTest
node tools/dev/php.mjs test --filter=InstallerTest
node .agents/scripts/validate-engineering-os.mjs --strict
node tools/dev/php.mjs run /tmp/final/bin/install.php --driver=sqlite --url=http://localhost:8080
node tools/dev/php.mjs run /tmp/final/bin/check-requirements.php
node tools/dev/php.mjs run /tmp/final/bin/migrate.php --status
node tools/dev/php.mjs run /tmp/final/bin/smoke.php
node tools/dev/php.mjs run /tmp/final/bin/cron.php
node tools/dev/php.mjs run /tmp/final/bin/cron.php          # اجرای دوم: idempotency
node tools/dev/php.mjs run /tmp/final/bin/queue.php
```

سناریوی نصب تمیز (`/tmp/final` = کپی فقط از `app bin config database public`, بدون `storage`):

- `check-requirements.php` پیش از نصب → `FAIL`های درست (پوشه‌ها و فایل پیکربندی) و `exit=1`
- `install.php` → ۵ مرحله، `storage/sessions` با `0700`، `config/config.php` با `0640`، دو مهاجرت
  اعمال‌شده، `exit=0`
- اجرای دوباره‌ی `install.php` → «فایل پیکربندی از قبل وجود دارد» و `exit=1` (بازنویسی تصادفی ممنوع)
- `smoke.php` → ۵ بررسی PASS، `0 check(s) failed`
- `cron.php` دو بار → بار اول job نگهداری را ثبت و اجرا می‌کند، بار دوم همان نتیجه‌ی نهایی
  (`maintenance.purge_rate_limits` دو ردیف `succeeded`، صف خالی)، `exit=0`
- `queue.php` → شمارش‌ها و آخرین job‌ها، `exit=0`

کنترل‌های جهش (هر بار یک تغییر عمدی، اجرا، سپس بازگردانی کامل و اجرای دوباره — همه **گرفته شدند**):

1. `Csrf::validate()` همیشه `true` → `CsrfTest` (۲ تست) و `SecurityMiddlewareTest` قرمز شدند
2. حذف شرط `status = 'queued'` از `Queue::claimOne()` →
   `QueueTest::testTheAtomicClaimRefusesARowThatIsNoLongerQueued` قرمز شد
3. `RateLimiter` همیشه یک بازدید می‌شمارد → ۵ تست `RateLimiterTest` قرمز شدند
4. (از برش قبل، بازاجرا نشد) گاردهای برش ۱ و ۲

## RESULTS

- `lint`: `PHP lint (PHP 8.4 via WebAssembly): 65 file(s)` → `checked 65 file(s), 0 failed` →
  `PHP lint passed.`
- `test`: `Tests: 141 passed, 0 failed, 497 assertions, 1872 ms`
  (پیش از این برش: ۸۶ تست / ۳۱۲ assertion؛ این برش ۵۵ تست و ۱۸۵ assertion افزود)
- validator: `9 checks passed, 0 warning(s), 0 error(s)` → `RESULT: PASS`
- قرارداد ستون‌ها با مهاجرت‌های موجود بررسی شد و **نیازی به مهاجرت ۰۰۰۳ نبود**:
  `sessions: id, token_hash, user_id, ip_address, user_agent, created_at, last_seen_at, expires_at` ·
  `rate_limits: bucket, key_hash, window_started_at, hits, expires_at` ·
  `jobs: id, queue, type, payload, attempts, max_attempts, status, available_at, reserved_at,
  reserved_by, finished_at, last_error, created_at`
  (همه توسط تست‌های همین برش روی مهاجرت‌های واقعی ۰۰۰۱/۰۰۰۲ اجرا می‌شوند)
- پاکیزگی مخزن پس از اجرای کامل تست‌ها: `ls -A storage/` → `.gitkeep` (بدون فایل نشست، بدون
  دیتابیس، بدون پوشه‌ی ساخته‌شده)

## SECURITY REVIEW

- **کوکی نشست**: `HttpOnly` همیشه، `SameSite=Lax` همیشه، `Secure` فقط روی HTTPS تشخیص‌داده‌شده،
  `use_strict_mode` (شناسه‌ای که سرور صادر نکرده پذیرفته نمی‌شود)، `use_only_cookies` (شناسه در URL
  نمی‌رود). شناسه هرگز لاگ نمی‌شود و در URL قرار نمی‌گیرد.
- **افشا**: پایگاه‌داده فقط `sha256` شناسه را می‌بیند؛ IP در جدول `rate_limits` هش می‌شود؛ پیام
  خطاهای پایگاه‌داده هیچ‌گاه SQLSTATE، نام جدول یا مسیر سرور را به کلاینت نمی‌دهد (تست‌شده).
- **CSRF**: deny-by-default روی هر متد تغییردهنده، پیش از کنترلر؛ توکن فقط از هدر/بدنه؛ اختلاف
  `Origin` رد می‌شود؛ `SameSite=Lax` لایه‌ی دوم است نه تنها لایه.
- **مصرف منابع**: اندازه‌ی بدنه‌ی مؤثر محدود می‌شود (اعلام‌شده و اندازه‌گیری‌شده)، نوشتن ناشناس
  سقف ساعتی دارد، اجرای کران بچ‌محدود و زمان‌محدود است و با قفل فایل از هم‌پوشانی دو اجرا جلوگیری
  می‌شود.
- **یکپارچگی داده**: محدودیت‌های پایگاه‌داده به‌عنوان منبع حقیقت باقی می‌مانند (FK/NOT NULL/UNIQUE)، و
  اکنون درست طبقه‌بندی می‌شوند، پس دیگر هیچ نقض محدودیتی «بی‌صدا» نیست.
- **افشای سطح اپراتور**: `bin/queue.php` و `bin/cron.php` فقط CLI اجرا می‌شوند و در صورت درخواست HTTP
  کد `403` می‌دهند.

## PERFORMANCE REVIEW

- بازدیدکننده‌ی ناشناس روی صفحه‌ی ساده: بدون نوشتن ردیف نشست و **بدون هیچ پرس‌وجوی پایگاه‌داده**
  (تست: `testAnAnonymousPageViewDoesNotCreateSessionState`، `testAnonymousSessionCreatesNoDatabaseRow`).
- نشست ورودکرده: حداکثر یک `SELECT` برای ردیف + یک `UPDATE` هر ۶۰ ثانیه (به‌جای هر درخواست).
- rate limit: یک `UPDATE` (و فقط یک `INSERT` در اولین درخواست) برای هر نوشتن ناشناس.
- صف: تصاحب با یک `SELECT` + یک `UPDATE` برای هر job؛ ایندکس‌های `jobs_status_available_at_index` و
  `jobs_queue_index` از برش ۲ موجودند و همان‌ها استفاده می‌شوند.
- هیچ hot path‌ای روی نشست/limit برای درخواست GET ناشناس اضافه نشده است.

## REGRESSION REVIEW

- همه‌ی ۸۶ تست برش‌های ۱ و ۲ بدون تغییر انتظارها سبز ماندند؛ تغییرهای این برش روی مسیرهای موجود
  (نشست در middleware، کلاس خطای پایگاه‌داده، `Request::create`) با تست‌های قبلی پوشش داده شده‌اند.
- رفتار عمومی خطای پایگاه‌داده تغییر نکرد: یک خرابی غیرمرتبط با نصب، همان `500` عمومی بدون افشا
  می‌ماند (تست `ErrorHandlerTest::testRuntimeDatabaseFailureStaysAGeneric500WithoutInternals`).
- مسیر front controller با پیکربندی ناقص (بدون مهاجرت) دیگر ۵۰۰ نمی‌دهد؛ درخواست ناشناس سرو می‌شود و
  درخواست نیازمند داده، `503 setup_required` با راهنمای دستور می‌گیرد.
- XAMPP/Laragon: مسیر زیرپوشه و اجرا بدون `mod_rewrite` همچنان تست‌شده است (تست‌های front controller).

## REMAINING RISKS

1. **موتور فرضیِ MySQL-خانواده (تصویب‌نشده، `O-2`) اجرا نشده است.** درایور آن در محیط توسعه
   به‌جای استثنای قابل‌گرفتن، کل runtime را از کار می‌اندازد؛ بنابراین این شاخه (DSN، مهاجرت‌ها،
   `UPDATE`های شرطی) فقط
   «کد بررسی‌شده + قرارداد تست‌شده» است، نه اجراشده. اولین کار روی هاست واقعی باید اجرای همین
   سناریوی نصب با `--driver=mysql` باشد.
2. **هیچ اجرایی روی هاست واقعی انجام نشده** (`O-20`): نصب، کران و سیاست نشست روی XAMPP/Laragon/`php -S`
   باید توسط owner اجرا و دیده شود. راهنمای آن در رانبوک (0.10) خواهد آمد.
3. **مهلت اجرای هر job وجود ندارد.** handler بی‌نهایت‌طول، دسته را تا `max_execution_time` نگه
   می‌دارد. تا زمانی که اولین handler واقعی (پیامک/تسویه) نوشته شود قابل قبول است، ولی پیش از
   آن باید timeout و dead-letter اضافه شود (در ADR-0006 ثبت شد).
4. **کران نیاز به تنظیم روی هاست دارد.** بدون cron هاست، کارهای پس‌زمینه اجرا نمی‌شوند و صف بزرگ
   می‌شود؛ این یک گام نصب است و باید در رانبوک و در گزارش توانمندی محیط صریح باشد.
5. **مقدار limit نوشتن ناشناس تجربی است** (پیش‌فرض ۳۰ در ساعت): پس از راه‌اندازی باید با ترافیک
   واقعی بازبینی شود.
6. **تست مرورگری وجود ندارد** (هارنس JS، مورد 0.9): همه‌ی تست‌های این برش سمت سرور هستند.

## NEXT ACTION

برش بعدی فاز ۰ به انتخاب owner: سیستم طراحی RTL (0.8)، هارنس تست مرورگر (0.9)، یا رانبوک نصب و
استقرار (0.10). پیشنهاد: 0.10 پیش از 0.8، چون owner باید همین حالا بتواند نصب را روی localhost اجرا
کند و نتیجه را ببیند؛ سپس 0.8 که ورودی فاز ۱ است. کارهای باقی‌مانده‌ی فاز ۰ در
[roadmap.md](../../product/roadmap.md) به‌روزرسانی شده‌اند.
