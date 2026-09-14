<?php

declare(strict_types=1);

/**
 * Environment report - "what does this environment actually provide?"
 *
 * Answers that question for a local machine (XAMPP, Laragon, `php -S`), a staging box or the real
 * host, so decisions rest on measured facts instead of assumptions (`O-2`, `O-3`, `O-20`).
 *
 * Usage
 *   php bin/check-requirements.php
 *   php bin/check-requirements.php --config=/path/to/config.php
 *   php bin/check-requirements.php --json > host-facts.json
 *
 * Safe by design: it writes nothing except a short-lived probe file inside `storage/` (which it
 * removes again - the only trustworthy way to know a directory is writable), changes nothing, and
 * prints no credentials. Connection results are reduced to a boolean plus a server version string.
 *
 * Exit codes
 *   0  every REQUIRED item is satisfied
 *   1  at least one required item is not satisfied
 *   2  bad usage
 *
 * The report has three kinds of line. `FAIL` stops an installation. `WARN` means a feature degrades
 * (payments, SMS, large uploads). `FACT` is an observed value with no pass/fail meaning - recording
 * those is the whole point of this script when it runs on the real host, because they are what fills
 * `O-20`.
 */

if (PHP_SAPI !== 'cli') {
    // A web-accessible capability report would hand an attacker a map of the installation. If a host
    // offers no shell at all, the capability check happens inside the installer instead (Phase 0,
    // item 0.10) - see the runbook.
    http_response_code(403);
    exit("check-requirements.php must be run from the command line.\n");
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app', 'App\\');
App\Core\Clock::init();

use App\Core\Config;
use App\Core\Install\RequirementsChecker;

$asJson = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--config=')) {
        // Passed through the process environment: environment variables are not reliably visible to
        // PHP on shared hosting, and reading argv here is still the installer's job (bin/install.php).
        putenv('CHAPINO_CONFIG=' . substr($argument, 9));
    } elseif ($argument === '--json') {
        $asJson = true;
    } else {
        fwrite(STDERR, "گزینه ناشناخته: {$argument}\n");
        exit(2);
    }
}

$config = null;
$configError = null;
try {
    $config = Config::load($root);
} catch (Throwable $e) {
    // No configuration yet (a fresh upload): the report still runs and says so, because that is
    // exactly the state in which someone wants to know what the environment provides.
    $configError = $e->getMessage();
}

$checker = new RequirementsChecker($root, $config);
$checks = $checker->run();
$summary = $checker->summary();

if ($asJson) {
    echo json_encode(
        [
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'minimum_required' => RequirementsChecker::MINIMUM_PHP],
            'config' => $config !== null ? 'loaded' : 'missing: ' . (string) $configError,
            'checks' => $checks,
            'facts' => $checker->facts(),
            'summary' => $summary,
        ],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n";

    exit($summary['ready'] ? 0 : 1);
}

echo "گزارش محیط اجرا — چاپینو\n";
echo str_repeat('=', 78) . "\n";

if ($configError !== null) {
    echo "توجه: فایل پیکربندی خوانده نشد ({$configError})\n";
    echo "       بدین ترتیب بررسی‌های وابسته به پیکربندی انجام نشده‌اند.\n\n";
}

$markers = ['required' => ['ok' => 'OK  ', 'bad' => 'FAIL'], 'optional' => ['ok' => 'OK  ', 'bad' => 'WARN'], 'fact' => ['ok' => 'FACT', 'bad' => 'FACT']];
$titles = [
    'required' => 'الزامات (بدون این‌ها نصب کامل نمی‌شود)',
    'optional' => 'اختیاری (در صورت نبود، بخشی از قابلیت‌ها محدود می‌شود)',
    'fact' => 'واقعیت‌های محیط (برای پر کردن O-20؛ معیار قبولی/رد نیستند)',
];

foreach (['required', 'optional', 'fact'] as $kind) {
    echo "\n" . $titles[$kind] . "\n";
    echo str_repeat('-', 78) . "\n";

    foreach ($checks as $check) {
        if ($check['kind'] !== $kind) {
            continue;
        }

        $marker = $check['ok'] ? $markers[$kind]['ok'] : $markers[$kind]['bad'];
        // No column padding: `printf("%-34s")` counts BYTES, and a Persian name is two bytes per
        // character, so every line would be misaligned in a way that looks like a bug in the report.
        // A separator keeps each line self-contained in any terminal.
        printf("%s  %s — %s\n", $marker, $check['name'], $check['detail']);
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
printf(
    "الزامات برقرارنشده: %d ، هشدار اختیاری: %d ، واقعیت ثبت‌شده: %d\n",
    $summary['required_failures'],
    $summary['optional_warnings'],
    $summary['facts'],
);

if ($summary['ready']) {
    echo "\nنتیجه: الزامات این محیط برقرار است.\n";
    echo "این خروجی را برای تکمیل O-20 نگه دارید: php bin/check-requirements.php --json > host-facts.json\n";
    exit(0);
}

echo "\nنتیجه: این محیط هنوز آماده نیست. موارد FAIL بالا را رفع کنید و دوباره اجرا کنید.\n";
exit(1);
