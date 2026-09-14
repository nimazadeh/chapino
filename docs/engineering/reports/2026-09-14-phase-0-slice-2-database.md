# Engineering Report - Phase 0 slice 2: data layer, migrations, installer

- Date: 2026-09-14
- Workflow: `feature-development`
- Branch: `arena/01a09e2c-chapino`
- Revision verified: `a54db98` + the working tree of this slice (the tree was dirty while the checks ran; the commit is named in the final section)
- Rules applied: `database`, `backend`, `security`, `qa`, `code-review`, `reporting`, `git-workflow`

> Format and vocabulary: [.agents/rules/reporting.md](../../../.agents/rules/reporting.md).
> Every claim below is backed by an executed command and its observed output.
> Prose is in Persian because the requester writes Persian; keywords, paths and commands stay English.
>
> **Technology status in this report.** The engine family is an **owner-set working assumption that is
> not ratified** (`O-2`, provisional): the MySQL family for the target host, and SQLite as the local
> test engine only. Neither is verified on a real host, and no host exists yet (`O-20`).

---

## STATUS

`PASS WITH RISKS`

داده‌محور، مهاجرت‌ها و نصب‌کننده پیاده‌سازی و **اجرا** شدند، به‌طور کامل روی SQLite (موتور تست محلی
که owner در `O-2` انتخاب کرده است) و با همان کدی که روی هاست اجرا می‌شود. دو مورد اجرانشده باقی است:
اتصال واقعی به موتور فرضی MySQL-خانواده (provisional، تأییدنشده) و نصب روی هاست واقعی — چون هیچ
هاستی وجود ندارد و درایور آن موتور در محیط توسعه‌ی فعلی به‌جای استثنای قابل‌گرفتن، کل runtime را از
کار می‌اندازد. پس `PASS` بدون قید در دسترس نیست.

## IMPLEMENTED

**لایه پایگاه‌داده** (`app/Core/Database/`) — همه‌ی کد مستقل از موتور نوشته شده و موتور از پیکربندی
می‌آید (فرضی و تأییدنشده: `O-2`):

- `Connection.php` — تنها مسیر دسترسی به پایگاه‌داده؛ PDO با دو درایور پشتیبانی‌شده (یکی برای
  خانواده‌ی فرضی MySQL و یکی به‌عنوان موتور تست محلی که owner تصویب کرده). متدهای
  `select/first/scalar/execute/insert/update/delete` همه پارامتری‌اند، `update()` و `delete()` شرط
  خالی را رد می‌کنند (حذف تصادفی همه‌ی ردیف‌ها ناممکن است)، `transaction()` تودرتو امن است، شناسه‌ها
  با `^[A-Za-z_][A-Za-z0-9_]*$` اعتبارسنجی و سپس quote می‌شوند، و پیش از هر اتصال وجود درایور PDO
  بررسی می‌شود تا نبودِ افزونه به پیام فارسی قابل‌اقدام تبدیل شود.
  روی موتور تست محلی: `PRAGMA foreign_keys=ON` و `busy_timeout=5000`. روی خانواده‌ی فرضی:
  `utf8mb4` و `ATTR_EMULATE_PREPARES=false` (provisional، تأییدنشده).
- `TableDefinition.php` — تعریف اعلانی جدول با رندر مستقل برای هر درایور
  (`INTEGER PRIMARY KEY AUTOINCREMENT` برای موتور تست محلی و `BIGINT UNSIGNED AUTO_INCREMENT` +
  `ENGINE=InnoDB` + charset/collation برای خانواده‌ی فرضی، نه یک تصمیم ratified)؛ طول پیش‌فرض رشته
  **191** برای محدودیت ایندکس `utf8mb4`؛ اصلاح‌کننده‌های زنجیره‌ای (`nullable/default/unique/index/
  foreign`) و خطای صریح اگر پیش از تعریف ستون استفاده شوند.
- `Schema.php` — `create/createIfMissing/dropIfExists/hasTable/tables/hasColumn/hasIndex/addColumn`.
- `MigrationException.php` / `DatabaseException.php` — پیام فارسی امن + کد ماشین‌خوان؛
  `isDuplicate()` برای تخلف قید یکتا و `SETUP_CODES` برای تفکیک «نصب ناقص» از «خطای زمان اجرا».

**مهاجرت‌ها** (`database/migrations/`):

- `0001_create_identity_tables.php` — `users` (ورود با موبایل، بدون ستون رمز، `role`, `owner_type`,
  `is_active`, زمان‌ها)، `sessions` (توکن **هش‌شده**)، `settings`.
- `0002_create_operations_tables.php` — `jobs` (صف کار با `reserved_at`/`attempts`/`available_at`)،
  `rate_limits`، `audit_log` (فقط-افزودنی).
- `app/Core/Database/Migrator.php` — کشف مرتب فایل‌های شماره‌دار، جدول `migrations` با شماره‌ی دسته،
  `up/down/status/fresh`؛ اجرای دوباره هیچ تغییری اعمال نمی‌کند؛ مهاجرت ناموفق **ثبت نمی‌شود**؛
  `fresh(false)` عمداً خطا می‌دهد.

**نصب و بررسی محیط** (`app/Core/Install/`, `bin/`):

- `RequirementsChecker.php` — گزارش فارسی از نسخه PHP، افزونه‌های الزامی/اختیاری، درایور پایگاه‌داده،
  دسترسی نوشتن، پیکربندی، دسترسی HTTP خروجی، سقف آپلود. **هیچ پوشه‌ای نمی‌سازد** (اصلاح ریشه‌ای؛
  پیش‌تر چک‌کردنِ پوشه خودش آن را می‌ساخت و نتیجه‌ی چک را بی‌معنا می‌کرد).
- `Installer.php` — ساخت پوشه‌های نوشتنی + `.htaccess` محدودکننده در `uploads`، بررسی اتصال پیش از
  نوشتن، نوشتن `config/config.php` (بازنویسی فقط با `--force`) و اجرای مهاجرت‌ها.
- `bin/install.php`, `bin/migrate.php`, `bin/check-requirements.php` — فقط CLI (از وب ۴۰۳)، خروجی
  فارسی، exit code معنادار.
- `bin/smoke.php` — دو چک جدید: اتصال پایگاه‌داده و «مهاجرت در انتظار = ۰».
- `app/Core/Application.php` — `database()` به‌عنوان تنها اتصال هر درخواست؛
  `app/Core/ErrorHandler.php` — خطاهای «نصب ناقص» → **503 `setup_required`** با پیام قابل‌اقدام و
  خطای معمول پایگاه‌داده → 500 عمومی بدون افشای SQL یا مسیر.
- `app/Controllers/HealthController.php` — `database: {driver, connected, migrations_pending}`
  بدون هیچ مسیر یا اعتبارنامه‌ای.

**اصلاح ریشه‌ای مسیر درخواست** (`app/Core/Request.php`):

- پیشوند پوشه‌ی نصب (مثل `/chapino/public`) از مسیر حذف می‌شود. این ایراد **با اجرای تست** پیدا شد:
  پیش از اصلاح، نصب داخل زیرپوشه (حالت معمول XAMPP/Laragon) روی **همه‌ی** مسیرها 404 می‌داد.
  همچنین مسیری که از `?r=` می‌آید و با `..` بالا می‌رود رد می‌شود.
- `app/Core/Config.php` — مسیرهای نسبی نسبت به **ریشه‌ی همان نصب** حل می‌شوند، نه نسبت به
  `APP_ROOT`؛ پیش از اصلاح، تست‌ها فایل پایگاه‌داده را داخل مخزن می‌ساختند.
- `config/config.example.php` — بخش `database` با `collation` و `sqlite_path` مستند شد.
- `tests/` — چهار کلاس جدید (`Integration/DatabaseTest`, `Integration/MigrationTest`,
  `Integration/InstallerTest`, `Unit/ErrorHandlerTest`) به‌علاوه‌ی تست‌های زیرپوشه، مسیر و سلامت.

**عمداً تغییر نکرد:** هیچ فیچر محصولی (طراحی، آپلود، سفارش) ساخته نشد؛ نشست/CSRF/rate-limit و صف
کار و کران به برش بعدی فاز ۰ موکول شد؛ هیچ کتابخانه‌ای اضافه نشد (بدون Composer، بدون ORM، بدون
انتخاب ratified برای هیچ فناوری).

## ROOT CAUSES

ایرادهایی که در همین برش پیدا و رفع شدند — هر کدام با اجرا، نه با بازخوانی:

1. **درج ستون دارای کاما** — `Schema::addColumn()` متن `CREATE TABLE` را با `, ` می‌شکست، پس هر
   ستونی با کاما (مثل `DECIMAL(14, 2)`) آن را خراب می‌کرد. ریشه: استخراج رشته‌ای به‌جای رندر مستقیم.
   رفع: متد `columnDefinitions()` و استفاده‌ی مستقیم از آن.
2. **`unique()` بدون ستون** — امضای متد ستون نمی‌گرفت، در حالی که بیلدر آن را زنجیره‌ای صدا می‌زند.
   ریشه: ناسازگاری API با سبک استفاده. رفع: `unique()` روی آخرین ستون اعمال می‌شود، و
   `nullable()/default()` پیش از ستون خطای صریح می‌دهند؛ بی‌صدا بی‌اثر بودن در اسکیما بدترین حالت است.
3. **تستِ وابسته به محیط** — «مسیر نامعتبر» برای موتور تست محلی روی فایل‌سیستم شبیه‌سازی‌شده موفق
   می‌شد، پس تست نمی‌توانست شکست بخورد. ریشه: انتخاب ورودی وابسته به مجوزهای سیستم‌عامل. رفع:
   مسیری که در هر محیطی شکست می‌خورد (اشاره به یک پوشه‌ی موجود) + گارد اختصاصی با پیام فارسی.
4. **نبودِ گارد درایور PDO** — با نبودِ افزونه روی هاست، کاربر فقط صفحه‌ی سفید یا پیام
   «could not find driver» می‌دید. رفع: بررسی `PDO::getAvailableDrivers()` پیش از اتصال.
5. **تمایزنداشتن خطای نصب از خطای اجرا** — خطای نصب به‌شکل 500 عمومی نمایش داده می‌شد. رفع:
   `SETUP_CODES` و نگاشت به 503 با پیام فارسی قابل‌اقدام.
6. **پوشه‌سازی در چک** — `RequirementsChecker` پوشه‌ها را می‌ساخت، پس همیشه «OK» می‌داد و خودش عامل
   موفقیت خودش بود. رفع: چک بدون هیچ نوشتن؛ ساخت پوشه کار نصب‌کننده است.
7. **مسیر نسبی نسبت به `APP_ROOT`** — در تست‌ها و نصب‌های دوم، فایل‌ها در نصب اول ساخته می‌شدند.
   رفع: `Config` ریشه‌ی خودش را می‌شناسد.
8. **پیشوند زیرپوشه در مسیر** — `SCRIPT_NAME` کامل حذف می‌شد، ولی `REQUEST_URI` در نصب زیرپوشه فقط
   نام پوشه را دارد؛ نتیجه 404 روی همه‌ی مسیرها. رفع: حذف پوشه‌ی اسکریپت هم، با تست واحد و تست
   front controller.

## TESTS EXECUTED

```bash
node tools/dev/php.mjs lint
node tools/dev/php.mjs test
node tools/dev/php.mjs test --filter=DatabaseTest
node tools/dev/php.mjs test --filter=MigrationTest
node tools/dev/php.mjs test --filter=InstallerTest
node tools/dev/php.mjs test --filter=RequestTest
node tools/dev/php.mjs test --filter=FrontControllerSmokeTest
node .agents/scripts/validate-engineering-os.mjs --strict
node tools/dev/php.mjs run /tmp/instest/bin/install.php --driver=sqlite --url=http://localhost:8080 --skip-requirements
node tools/dev/php.mjs run /tmp/instest/bin/install.php --driver=sqlite --skip-requirements
node tools/dev/php.mjs run /tmp/instest/bin/migrate.php --status
node tools/dev/php.mjs run /tmp/instest/bin/migrate.php --down
node tools/dev/php.mjs run /tmp/instest/bin/migrate.php
node tools/dev/php.mjs run /tmp/instest/bin/migrate.php --fresh
node tools/dev/php.mjs run /tmp/instest/bin/migrate.php --fresh --force
node tools/dev/php.mjs run /tmp/instest/bin/check-requirements.php
node tools/dev/php.mjs run /tmp/instest/bin/smoke.php --config=/tmp/instest/config/config.php
node tools/dev/php.mjs run bin/smoke.php
```

کنترل‌های منفی (چهار مورد؛ هر بار یک تغییر عمدی، اجرا، سپس بازگردانی کامل و اجرای دوباره):

1. حذف گارد «شرط خالی» در `delete()`
2. خاموش‌کردن `PRAGMA foreign_keys` (مسیر موتور تست محلی)
3. نادیده‌گرفتن مهاجرت‌های اعمال‌شده در محاسبه‌ی pending
4. حذفِ حذف‌کردن پیشوند زیرپوشه در `Request::resolvePath`

## RESULTS

- `lint`: `PHP lint (PHP 8.4 via WebAssembly): 48 file(s)` → `checked 48 file(s), 0 failed` →
  `PHP lint passed.` (lint و تست روی PHP اجرا می‌شوند؛ هیچ موتور پایگاه‌داده‌ای در این مرحله لازم نیست)
- `test`: `Tests: 86 passed, 0 failed, 312 assertions, ~0.8 s` (پیش از این برش: ۴۸ تست؛ این شمارش از
  اجرای واقعی runner است، نه تخمین).
- `validate-engineering-os.mjs --strict`: `9 checks passed, 0 warning(s), 0 error(s)` → `RESULT: PASS`.
- نصب روی درخت تازه (`/tmp/instest`) بدون shell:
  `۱) آماده‌سازی… ساخته شد: storage, storage/logs, storage/cache, storage/uploads, storage/tmp,
  storage/backups` · `۲) اتصال برقرار شد — SQLite 3.51.0` (خط اجرای واقعی؛ این موتور، موتور تست
  محلی است که owner در `O-2` تصویب کرده، فرضی برای هاست نیست) · `۳) نوشته شد: config/config.php` ·
  `۴) اجرا شد: 0001_create_identity_tables, 0002_create_operations_tables` → `exit=0`.
- اجرای دوباره‌ی نصب‌کننده: `فایل پیکربندی از قبل وجود دارد. برای بازنویسی، گزینه --force را اضافه
  کنید.` → `exit=1` و **هیچ فایلی بازنویسی نشد**.
- کنترل منفی روی وایتور: یک ادعای عمدی در متن (بدون qualifier) هشدار `premature-stack` را بالا آورد،
  در حالی که همان نام فناوری داخل بلوک کدِ شواهد هشدار نمی‌دهد. به همین دلیل این چک، بلوک‌های کد را
  از داوری متن کنار گذاشت (شواهد باید عیناً نقل شوند)؛ سطرهای متن همچنان باید خود را qualify کنند.
- `migrate --status`: `2 اعمال‌شده، 0 در انتظار` · پس از `--down`: `0 اعمال‌شده، 2 در انتظار` ·
  اجرای دوباره: هر دو اعمال شد · `--fresh` بدون `--force`: پیام هشدار، `exit=2` ·
  `--fresh --force`: `پایگاه‌داده از نو ساخته شد`.
- `check-requirements.php` پس از نصب: `مورد نیاز برقرارنشده: 0 ، هشدار اختیاری: 2` (هشدارها: نبود
  `intl` و نبود دسترسی خروجی HTTP در sandbox) → `نتیجه: این محیط برای اجرای برنامه آماده است.` `exit=0`.
- `smoke.php --config=…`: پنج PASS، `0 check(s) failed`, `exit=0` — شامل
  `اتصال پایگاه‌داده driver=sqlite، SQLite 3.51.0` و `مهاجرت‌های پایگاه‌داده 2 اعمال‌شده، 0 در انتظار`
  (این اجرا با موتور تست محلی است که owner در `O-2` تصویب کرده؛ موتور فرضی هاست هنوز provisional و تأییدنشده است).
- `smoke.php` بدون پیکربندی: **چهار خطای صادقانه** (`503 config_error` + راهنما) و `exit=1`؛ یعنی
  ابزار self-check واقعاً می‌تواند شکست بخورد.
- با مهاجرت‌های بازگردانی‌شده: `FAIL مهاجرت‌های پایگاه‌داده 2 در انتظار: …` → `exit=1`؛ پس از اجرای
  دوباره‌ی مهاجرت‌ها: `0 check(s) failed` → `exit=0` (کنترل منفی متناسب).
- کنترل‌های منفی تست‌ها، هر چهار مورد شکست مورد انتظار را دادند و پس از بازگردانی، مجموعه دوباره
  `86 passed, 0 failed` شد:
  - حذف گارد `delete` → `FAILED: DatabaseTest::testDeletingWithoutAConditionIsRefused`
  - خاموش‌کردن foreign keys → `FAILED: DatabaseTest::testForeignKeysAreEnforced … nothing was thrown`
  - pending نادیده‌گیرنده → `FAILED: MigrationTest::testStatusReportsPendingThenNothingPendingAfterApply`
    و `testRunningMigrationsAgainIsIdempotent` (۲ تست)
  - حذف پیشوند زیرپوشه → `FAILED: RequestTest::testSubfolderDocumentRootPrefixIsNotPartOfTheRoute —
    Expected "/", got "/chapino/public"`
- **اجرانشده (و چرا):** اتصال واقعی به موتور فرضی MySQL-خانواده و نصب روی هاست — چون نه آن موتور در
  دسترس است (provisional، تأییدنشده) و نه هاستی وجود دارد. در runtime توسعه (WASM PHP 8.4)
  `pdo_mysql` کامپایل شده ولی `new PDO('mysql:…')` به‌جای استثنای قابل‌گرفتن، تله‌ی WASM می‌دهد و
  پروسه را می‌کشد (`RuntimeError: unreachable at … php_pdo_internal_construct_driver`). پس از این
  لایه، فقط رندر SQL و گاردهای موتور فرضی تست شده‌اند، نه اتصال آن.

## SECURITY REVIEW

- **تزریق SQL:** همه‌ی کوئری‌ها پارامتری؛ هیچ API‌ای SQL رشته‌ای نمی‌پذیرد. نام جدول/ستون با regex
  اعتبارسنجی و سپس quote می‌شود. تست `testUnsafeIdentifierIsRejectedInsteadOfInterpolated` نام جدول
  خرابکارانه را رد می‌کند.
- **حذف/به‌روزرسانی تصادفی:** `delete([])` و `update([], [])` استثنا می‌دهند (تست و کنترل منفی دارد).
- **افشای اطلاعات:** پیام خطا در پاسخ عمومی هرگز متن درایور، نام جدول یا مسیر نیست؛ 500 عمومی برای
  خطای زمان اجرا و 503 با پیام نوشته‌شده‌ی خودمان برای خطای نصب. تست‌ها `SQLSTATE`، نام جدول،
  `/home/` و `password` را در بدنه‌ی پاسخ منع می‌کنند.
- **نشست:** توکن نشست **هش‌شده** ذخیره می‌شود (`token_hash`) و تست، وجود ستون رمز عبور در `users` را
  رد می‌کند (طبق `O-6` هیچ رمزی در محصول وجود ندارد).
- **آپلود:** پوشه‌ی `uploads` با `.htaccess` (خاموش‌کردن موتور PHP و منع فایل‌های اجرایی) ساخته
  می‌شود و تست، وجود و محتوای آن را بررسی می‌کند. `storage` بیرون web root است.
- **دسترسی CLI:** `install.php`/`migrate.php`/`check-requirements.php` روی SAPI وب ۴۰۳ می‌دهند.
- **عملیات مخرب:** `--fresh` بدون `--force` اجرا نمی‌شود؛ بازنویسی پیکربندی بدون `--force` ممکن نیست.
- **ورودی مسیر:** مسیر `?r=` حاوی `..` رد می‌شود.
- **اعتبارنامه‌ها:** پیکربندی با `0640` نوشته می‌شود، در `gitignore` است و هرگز چاپ نمی‌شود؛ گزارش
  محیط فقط بولین و نسخه‌ی سرور را نشان می‌دهد.
- نامرتبط در این برش: CSRF، rate-limit و مدیریت نشست سمت سرور پیاده نشده‌اند (برش بعدی فاز ۰).

## PERFORMANCE REVIEW

- اندازه‌گیری روی محیط واقعی ممکن نبود (هیچ موتور MySQL-خانواده‌ای و هیچ وب‌سرویسی در دسترس نیست؛
  تأییدنشده). آنچه سنجیده شد:
  - تعداد اتصال: یک `Connection` برای هر درخواست (`Application::database()`)، بدون اتصال دوم.
  - ایندکس‌ها از روز اول روی ستون‌های پرس‌وجوشونده: `users.mobile` (unique)، `users.role`،
    `users.owner_type`، `sessions.token_hash` (unique)، `sessions.expires_at`،
    `jobs(status, available_at)`، `jobs.queue`، `rate_limits(bucket, key_hash, window_started_at)`
    (unique)، `rate_limits.expires_at`، `audit_log(subject_type, subject_id)`، `audit_log.action`،
    `audit_log.created_at`.
  - آماده‌سازی: `PDO::ATTR_EMULATE_PREPARES=false` (prepared واقعی سمت سرور).
  - طول ایندکس: پیش‌فرض ۱۹۱ کاراکتر برای `utf8mb4`.
  - زمان اجرای مجموعه تست: `~0.8 s` برای ۸۶ تست (شاهد غیرمستقیم سبک بودن لایه).
- پرس‌وجوی کند، N+1 یا کوئری اضافه‌ای وجود ندارد، چون هنوز هیچ فیچر محصولی روی این لایه ساخته نشده
  است؛ سنجش واقعی در فازهای بعدی و روی هاست انجام می‌شود.

## REGRESSION REVIEW

- رفتارهای در معرض خطر: مسیریابی بدون rewrite، صفحه‌ی فرود، `/api/health`، مدیریت خطا، لاگ،
  اعتبارسنجی فارسی، `bin/smoke.php` و رفتار «بدون پیکربندی».
- بازاجراشده: کل مجموعه تست (۸۶ تست) شامل تمام تست‌های برش ۱ — همه پاس. `bin/smoke.php` در هر سه
  حالت (بدون پیکربندی، نصب کامل، مهاجرت‌های بازگردانی‌شده) رفتار مورد انتظار را داشت. وایتور
  `--strict` هم ۹/۹ پاس شد.
- تغییر عمدی رفتار: چک‌های جدید پایگاه‌داده در `smoke.php` باعث می‌شود اجرای «بدون پیکربندی» به‌جای
  ۳ خطای قبلی، ۴ خطا بدهد. این تغییر خواسته‌شده است (نصب ناقص باید شکست بخورد) و همین‌جا ثبت می‌شود.
- سمت مرورگر و استایل در این برش دست نخورده است، پس رگرسیونی از آن جنس ممکن نبود.

## REMAINING RISKS

1. **موتور فرضی MySQL-خانواده اجرا نشده است (provisional، تأییدنشده).** نه اتصال واقعی، نه اجرای
   مهاجرت‌ها روی آن. فقط رندر SQL و گاردها تست شده‌اند. روی هاست اول باید
   `bin/install.php --driver=mysql …` و سپس `bin/smoke.php` و `bin/migrate.php --status` اجرا شود.
2. **تفاوت‌های دو موتور** (affinity نوع، collation فارسی، قفل همزمانی، رفتار شمارنده‌ی خودافزا)
   تأییدنشده‌اند؛ موتور تست محلی فقط شاهد محلی است و جانشین آزمایش هاست نیست.
3. **DDL تراکنشی** در موتور فرضی وجود ندارد — مهاجرت ناموفق می‌تواند نیمه‌کاره بماند. کد، مهاجرت
   خراب را نام می‌برد و `--status` را پیشنهاد می‌کند، ولی بازیابی خودکار وجود ندارد.
4. **نسخه PHP و افزونه‌های هاست (`O-20`) نامعلوم‌اند.** کد روی PHP 8.4 اجرا و تست شده و عمداً از
   هیچ قابلیت جدیدتر از ۸.۱ استفاده نمی‌کند، ولی این ادعا روی PHP 8.1 واقعی اجرا نشده است.
5. **بدون هاست واقعی، هیچ‌کدام از این‌ها روی هدف تأیید نشده‌اند:** وب‌سرور و `.htaccess`، مسیر
   زیرپوشه در محیط وب واقعی، cron، محدودیت اتصال، دسترسی خروجی HTTP. برای همین اکنون موتور فرضی و
   وب‌سرور در این گزارش «تأییدنشده» علامت خورده‌اند.
6. **نشست/CSRF/rate-limit** جدول‌هایشان ساخته شده اما منطقشان در برش بعدی فاز ۰ می‌آید؛ تا آن زمان
   `sessions`، `rate_limits` و `jobs` بی‌استفاده‌اند.
7. **نصب وب (بدون shell)** ساخته نشده؛ `Installer` برای آن آماده‌سازی شده، ولی مسیر وب + توکن
   یک‌بارمصرف + خودغیرفعال‌سازی در برش استقرار می‌آید.
8. **`O-10` (حجم ترافیک و شکل B2B) و `O-13` (چاپ‌خانه) باز است.** اسکیما فقط جایی که برگشت‌ناپذیری
   خطرناک بود انعطاف دارد (`owner_type`)، اما تصمیم‌های واقعی هنوز گرفته نشده‌اند.

## NEXT ACTION

برش ۳ فاز ۰: نشست/کوکی امن + CSRF + rate-limit مبتنی بر پایگاه‌داده، سپس صف کار و کران
(`bin/cron.php`)، و در ادامه سیستم طراحی RTL و رانبوک استقرار.
