<?php

declare(strict_types=1);

use App\Core\View;

/**
 * The web installer's page - one template, five screens.
 *
 * Why not the application's view layer: it cannot be used yet. `Config::load()` refuses to run without
 * `config/config.php`, and that file is what this page exists to create. What this template does reuse
 * is everything that is not configuration: the real stylesheets, the real design-system classes and
 * the real escaping helper. So the installer looks like the product, and behaves like a page whose
 * only job is to be read carefully by one person.
 *
 * @var string $screen   gate | form | success | error | disabled
 * @var array<string, mixed> $data
 */

$e = static fn (mixed $value): string => View::e($value);
$checks = is_array($data['checks'] ?? null) ? $data['checks'] : [];
$values = is_array($data['values'] ?? null) ? $data['values'] : [];
$errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$base = is_string($data['base'] ?? null) ? $data['base'] : '';

// Grouped the way an operator reads them: what blocks, what is merely missing, what is just a fact.
$grouped = ['required' => [], 'optional' => [], 'fact' => []];
foreach ($checks as $check) {
    $grouped[(string) ($check['kind'] ?? 'fact')][] = $check;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب چاپینو</title>
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/css/tokens.css">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/css/base.css">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/css/layout.css">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/css/components.css">
</head>
<body>
<div class="container container-narrow">
    <header class="install-header">
        <p class="text-muted text-sm">نصب چاپینو</p>
        <?php if ($screen === 'disabled'): ?>
            <h1 class="page-title">صفحه‌ی نصب غیرفعال است</h1>
        <?php else: ?>
            <h1 class="page-title">راه‌اندازی نصب</h1>
        <?php endif; ?>
    </header>

    <?php if ($screen === 'disabled'): ?>
        <section class="card stack">
            <p>
                این نصب از قبل انجام شده است، پس این صفحه دیگر کاری انجام نمی‌دهد و برای امنیت پاسخ
                <code>404</code> می‌دهد. اگر می‌خواهید نصب را از نو انجام دهید، ابتدا فایل
                <code>config/config.php</code> را از پنل هاست حذف کنید.
            </p>
            <p class="text-muted text-sm">
                برای به‌روزرسانی‌های بعدی (مهاجرت‌ها و تنظیمات پیش‌فرض) از صفحه‌ی
                <code>/maintenance</code> استفاده کنید.
            </p>
        </section>

    <?php elseif ($screen === 'success'): ?>
        <section class="card stack">
            <div class="alert alert-success" role="status">
                <strong>نصب کامل شد.</strong>
                پایگاه‌داده وصل شد، همه‌ی جدول‌ها ساخته و تنظیمات پیش‌فرض ثبت شد.
            </div>

            <table class="table">
                <tbody>
                <tr><th>نسخه‌ی پایگاه‌داده</th><td><?= $e($data['server_version'] ?? '') ?></td></tr>
                <tr><th>فایل پیکربندی</th><td><code><?= $e($data['config'] ?? '') ?></code></td></tr>
                <tr><th>مهاجرت‌های اجراشده</th><td><?= $e((string) count($data['migrations'] ?? [])) ?></td></tr>
                <tr><th>تنظیمات پیش‌فرض افزوده‌شده</th><td><?= $e((string) count($data['seeded']['added'] ?? [])) ?></td></tr>
                <tr>
                    <th>کلیدهایی که تصمیم شماست</th>
                    <td>
                        <?php if (($data['seeded']['owner_input'] ?? []) === []): ?>
                            موردی نیست
                        <?php else: ?>
                            <?php foreach ($data['seeded']['owner_input'] as $key): ?>
                                <div><code><?= $e($key) ?></code> — با <code>php bin/seed.php --status</code> می‌بینید</div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>

            <div class="alert alert-info">
                <strong>این صفحه از این لحظه غیرفعال است</strong> (چون <code>config/config.php</code> ساخته شد)
                و توکن یک‌بارمصرف هم مصرف شد. اگر خواستید، فایل <code>public/install.php</code> را هم از پنل
                حذف کنید؛ لزومی ندارد.
            </div>

            <h2>گام‌های بعدی</h2>
            <ol class="stack-sm">
                <li>
                    کران را در پنل هاست اضافه کنید (بدون آن صف کار نمی‌چرخد):
                    <pre class="code-block">*/1 * * * * <?= $e($data['php_binary'] ?? 'php') ?> <?= $e($data['app_root'] ?? '') ?>/bin/cron.php</pre>
                    مسیر واقعی PHP را از پنل هاست بردارید (مثلاً <code>/usr/bin/php</code> یا
                    <code>/opt/cpanel/ea-php81/root/usr/bin/php</code>).
                </li>
                <li>صفحه‌ی خانه را باز کنید: <a href="<?= $e($base ?: '/') ?>"><?= $e($base ?: '/') ?></a></li>
                <li>
                    بررسی‌های نصب را یک بار دیگر بزنید:
                    <code>php bin/check-requirements.php</code> و <code>php bin/smoke.php</code>
                    (اگر دسترسی ترمینال دارید) یا همان صفحه‌ی <code>/maintenance</code>.
                </li>
            </ol>
        </section>

    <?php else: ?>
        <?php if ($screen === 'gate'): ?>
            <section class="card stack">
                <?php if (($data['token_created'] ?? false) === true): ?>
                    <div class="alert alert-info" role="status">
                        <strong>یک توکن یک‌بارمصرف ساخته شد.</strong>
                        برای اثبات این‌که به فایل‌های نصب دسترسی دارید، فایل زیر را از
                        <em>فایل‌مدیر پنل هاست</em> باز کنید و مقدار داخل آن را در کادر پایین بگذارید:
                        <div><code><?= $e($data['token_path'] ?? '') ?></code></div>
                    </div>
                <?php else: ?>
                    <p>
                        برای ادامه، مقدار توکن یک‌بارمصرف را از فایل زیر (فایل‌مدیر پنل هاست) بردارید و
                        در کادر پایین بگذارید. این مقدار روی صفحه چاپ نمی‌شود: فقط کسی که به فایل‌های
                        نصب دسترسی دارد می‌تواند آن را بخواند.
                    </p>
                    <div><code><?= $e($data['token_path'] ?? '') ?></code></div>
                <?php endif; ?>

                <?php if (($data['error'] ?? '') !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= $e($data['error']) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= $e($data['action'] ?? '') ?>" class="stack-sm">
                    <input type="hidden" name="_csrf" value="<?= $e($data['csrf'] ?? '') ?>">
                    <input type="hidden" name="action" value="authorize">
                    <div class="field">
                        <label class="field-label" for="token">توکن یک‌بارمصرف</label>
                        <input class="field-input" id="token" name="token" type="text" dir="ltr"
                               autocomplete="off" spellcheck="false" required>
                        <p class="field-help">۶۴ کاراکتر؛ از فایل بالا کپی کنید.</p>
                    </div>
                    <button class="btn btn-primary" type="submit">تأیید توکن</button>
                </form>
            </section>

        <?php elseif ($screen === 'error'): ?>
            <section class="card stack">
                <div class="alert alert-danger" role="alert">
                    <strong>نصب انجام نشد.</strong>
                    <div><?= $e($data['message'] ?? '') ?></div>
                    <?php if (($data['code'] ?? '') !== ''): ?>
                        <div class="text-sm text-muted">کد: <code><?= $e($data['code']) ?></code></div>
                    <?php endif; ?>
                </div>
                <p><a class="btn" href="<?= $e($data['form_action'] ?? '') ?>">بازگشت به فرم نصب</a></p>
            </section>

        <?php else: ?>
            <section class="card stack">
                <h2>۱. گزارش توانمندی این هاست</h2>
                <p class="text-muted text-sm">
                    این گزارش پیش از هر نوشتنی اجرا می‌شود. «الزامی» نبودش نصب را متوقف می‌کند.
                </p>

                <?php if (($summary['ready'] ?? false) !== true): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= $e((string) ($summary['required_failed'] ?? 0)) ?> مورد الزامی برقرار نیست؛
                        تا رفع نشود، دکمه‌ی نصب کار نمی‌کند.
                    </div>
                <?php endif; ?>

                <?php foreach (['required' => 'الزامی', 'optional' => 'اختیاری', 'fact' => 'واقعیت'] as $kind => $label): ?>
                    <?php if ($grouped[$kind] === []): continue; endif; ?>
                    <h3 class="text-sm"><?= $e($label) ?></h3>
                    <table class="table">
                        <tbody>
                        <?php foreach ($grouped[$kind] as $check): ?>
                            <tr>
                                <th>
                                    <?php if (($check['ok'] ?? false) === true): ?>
                                        <span class="badge">دارد</span>
                                    <?php elseif ($kind === 'required'): ?>
                                        <span class="badge badge-danger">ندارد</span>
                                    <?php else: ?>
                                        <span class="badge">نیست</span>
                                    <?php endif; ?>
                                    <?= $e($check['name'] ?? '') ?>
                                </th>
                                <td class="text-sm"><?= $e($check['detail'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </section>

            <section class="card stack">
                <h2>۲. تنظیمات پایگاه‌داده و آدرس سایت</h2>

                <?php if (($errors[''] ?? '') !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= $e($errors['']) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= $e($data['action'] ?? '') ?>" class="stack-sm">
                    <input type="hidden" name="_csrf" value="<?= $e($data['csrf'] ?? '') ?>">
                    <input type="hidden" name="action" value="install">

                    <div class="field">
                        <label class="field-label" for="driver">درایور پایگاه‌داده</label>
                        <select class="field-input" id="driver" name="driver" dir="ltr">
                            <option value="mysql"<?= ($values['driver'] ?? '') === 'mysql' ? ' selected' : '' ?>>mysql (روی هاست)</option>
                            <option value="sqlite"<?= ($values['driver'] ?? 'sqlite') === 'sqlite' ? ' selected' : '' ?>>sqlite (فقط آزمایشی)</option>
                        </select>
                        <p class="field-help">
                            روی هاست واقعی MySQL/MariaDB انتخاب کنید. SQLite برای امتحان سریع روی همین ماشین است.
                        </p>
                    </div>

                    <?php
                    $fields = [
                        'host' => ['هاست', 'localhost', 'text'],
                        'port' => ['پورت', '3306', 'text'],
                        'name' => ['نام پایگاه‌داده', 'chapino', 'text'],
                        'user' => ['کاربر پایگاه‌داده', 'chapino', 'text'],
                        'password' => ['رمز پایگاه‌داده', '', 'password'],
                        'url' => ['آدرس سایت (اختیاری)', 'https://example.ir', 'text'],
                    ];
                    foreach ($fields as $field => [$label, $placeholder, $type]):
                        ?>
                        <div class="field">
                            <label class="field-label" for="<?= $e($field) ?>"><?= $e($label) ?></label>
                            <input class="field-input<?= ($errors[$field] ?? '') !== '' ? ' is-invalid' : '' ?>"
                                   id="<?= $e($field) ?>" name="<?= $e($field) ?>" type="<?= $e($type) ?>"
                                   dir="ltr" placeholder="<?= $e($placeholder) ?>"
                                   value="<?= $e($values[$field] ?? '') ?>" autocomplete="off">
                            <?php if (($errors[$field] ?? '') !== ''): ?>
                                <p class="field-error"><?= $e($errors[$field]) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <p class="field-help">
                        فایل پیکربندی در <code>config/config.php</code> ساخته می‌شود و رمزها را در خود دارد؛
                        آن را جایی کپی نکنید و در گفت‌وگو یا تیکت نگذارید.
                    </p>

                    <button class="btn btn-primary" type="submit"
                            <?= ($summary['ready'] ?? false) === true ? '' : 'disabled' ?>>
                        نصب کن
                    </button>
                    <?php if (($summary['ready'] ?? false) !== true): ?>
                        <p class="field-help">
                            تا وقتی موارد الزامی بالا برقرار نشده، نصب انجام نمی‌شود.
                        </p>
                    <?php endif; ?>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <footer class="install-footer text-sm text-muted">
        <p>
            این صفحه فقط تا پیش از ساخته‌شدن <code>config/config.php</code> پاسخ می‌دهد و برای امنیت
            <code>noindex</code> است. توکن‌ها یک‌بارمصرف‌اند و پس از نصب پاک می‌شوند.
        </p>
    </footer>
</div>
</body>
</html>
