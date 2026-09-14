<?php

declare(strict_types=1);

/**
 * The browser test harness page (`O-11`, option 2).
 *
 * It renders the fixture template, then loads the shared case file and the runner. Everything else -
 * the assertions, the expectations, the reporting - lives in `tests/browser/`, so this page stays a
 * page: markup a person can read, with no behaviour of its own.
 *
 * Found here rather than in `public/` because it must not exist on a live installation (404, see
 * BrowserTestsController) and must not be copyable into the deployment by accident.
 *
 * Data contract:
 *   string $fixturesPath    absolute path to the fixture template (tests/browser/fixtures.html)
 *   string $harnessScript   URL of the runner (versioned)
 *   string $casesScript     URL of the shared cases (versioned)
 *
 * @var App\Core\View $view
 */

$fixturesPath = (string) ($fixturesPath ?? '');
$harnessScript = (string) ($harnessScript ?? '');
$casesScript = (string) ($casesScript ?? '');
?>

<section class="stack">
    <h1 class="page-title">تست مرورگر</h1>
    <p class="lead">
        این صفحه رفتار واقعی اسکریپت پایه (<code>public/assets/js/app.js</code>) را در همین مرورگر
        اجرا می‌کند: ارقام فارسی، قالب‌بندی مبلغ، تزریق توکن CSRF و قفل‌شدن دکمه در زمان ارسال.
        توسعه‌ی صفحه‌های پویا (ادیتور طراحی) این رفتارها را روی همین هارنس می‌سنجد.
    </p>
    <p class="text-muted text-sm">
        فقط در محیط توسعه سرو می‌شود و روی نصب واقعی وجود ندارد. نتیجه‌ی ماشینی هم در
        <code>window.harnessResult</code> در دسترس است.
    </p>
</section>

<section class="stack" aria-labelledby="browser-harness-result">
    <h2 id="browser-harness-result">نتیجه</h2>

    <div id="harness-summary" class="alert alert-info" role="status" aria-live="polite">
        در حال اجرا…
    </div>

    <p>
        <button type="button" id="harness-rerun" class="btn btn-secondary">اجرای دوباره</button>
    </p>

    <ul id="harness-report" class="harness-report"></ul>
</section>

<section class="stack" aria-labelledby="browser-harness-how">
    <h2 id="browser-harness-how">چطور اجرا می‌شود</h2>
    <div class="card stack">
        <ol class="stack-sm">
            <li>این صفحه در مرورگر باز می‌شود؛ نیازی به نصب هیچ ابزاری نیست.</li>
            <li>
                همان فایل موارد (<code>tests/browser/cases.js</code>) با
                <code>node tools/dev/browser-tests.mjs</code> هم اجرا می‌شود، تا نتیجه در محیط توسعه
                هم قابل ثبت باشد.
            </li>
            <li>
                اگر بررسی‌ای ناموفق شود، پیام دقیق زیر همان مورد نوشته می‌شود؛ همان متن را برای رفع
                اشکال بفرستید.
            </li>
        </ol>
    </div>
</section>

<?php
// The fixture markup itself. Included, never copied: the Node runner reads this exact file, so the
// two environments cannot drift into testing different fixtures.
if ($fixturesPath !== '' && is_file($fixturesPath)) {
    include $fixturesPath;
} else {
    echo '<p class="alert alert-danger">فایل فیکسچرها پیدا نشد؛ هارنس نمی‌تواند اجرا شود.</p>';
}
?>

<script src="<?= App\Core\View::e($casesScript) ?>" defer></script>
<script src="<?= App\Core\View::e($harnessScript) ?>" defer></script>
