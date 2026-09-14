<?php

declare(strict_types=1);

/**
 * The maintenance page (`/maintenance`).
 *
 * Two states, and the difference matters: before authorisation it only explains how to obtain the
 * token (and never shows it); after authorisation it shows what the installation currently holds and
 * offers the two operations an update consists of - applying migrations and applying defaults.
 *
 * Data contract:
 *   bool   $authorized
 *   string $token_path    path of the one-time token file, as an operator sees it
 *   bool   $token_created true when this request created the token file
 *   string $csrf
 *   string $error
 *   array  $migration_status  ['applied' => [...], 'pending' => [...]]
 *   array  $settings          stored settings
 *   array  $requirements      ['required_failed' => int, 'optional_missing' => int, ...]
 *   string $notice
 *
 * @var App\Core\View $view
 */

use App\Core\View;

$e = static fn (mixed $value): string => View::e($value);
$authorized = ($authorized ?? false) === true;
$error = (string) ($error ?? '');
$notice = (string) ($notice ?? '');
$csrf = (string) ($csrf ?? '');
?>

<section class="stack">
    <h1 class="page-title">نگهداری نصب</h1>
    <p class="lead">
        این صفحه برای میزبان‌هایی است که دسترسی ترمینال ندارند: مهاجرت‌های جدید و تنظیمات پیش‌فرض را
        از همین‌جا می‌توانید اعمال کنید. برای هر کار، یک بار اجازه‌ی دسترسی با توکن یک‌بارمصرف لازم است.
    </p>
</section>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success" role="status"><?= $e($notice) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
<?php endif; ?>

<?php if (!$authorized): ?>
    <section class="card stack">
        <?php if (($token_created ?? false) === true): ?>
            <div class="alert alert-info" role="status">
                <strong>یک توکن یک‌بارمصرف ساخته شد.</strong>
                فایل زیر را از فایل‌مدیر پنل هاست باز کنید و مقدار داخل آن را در کادر پایین بگذارید:
                <div><code><?= $e($token_path ?? '') ?></code></div>
            </div>
        <?php else: ?>
            <p>
                برای ادامه، مقدار توکن یک‌بارمصرف را از فایل زیر (فایل‌مدیر پنل هاست) بردارید. اگر فایل
                را نمی‌بینید، این صفحه را دوباره باز کنید تا ساخته شود.
            </p>
            <div><code><?= $e($token_path ?? '') ?></code></div>
        <?php endif; ?>

        <form method="post" action="<?= $e(($base ?? '') . '/maintenance') ?>" class="stack-sm">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="authorize">
            <div class="field">
                <label class="field-label" for="token">توکن یک‌بارمصرف</label>
                <input class="field-input" id="token" name="token" type="text" dir="ltr" autocomplete="off"
                       spellcheck="false" required>
                <p class="field-help">۶۴ کاراکتر؛ فقط کسی که به فایل‌های نصب دسترسی دارد می‌تواند آن را بخواند.</p>
            </div>
            <button class="btn btn-primary" type="submit">تأیید توکن</button>
        </form>
    </section>

<?php else: ?>
    <?php
    $status = is_array($migration_status ?? null) ? $migration_status : ['applied' => [], 'pending' => []];
    $requirements = is_array($requirements ?? null) ? $requirements : [];
    $stored = is_array($settings ?? null) ? $settings : [];
    ?>

    <section class="card stack">
        <h2>وضعیت مهاجرت‌ها</h2>
        <table class="table">
            <tbody>
            <tr>
                <th>اجرا شده</th>
                <td><?= $e((string) count($status['applied'])) ?> مهاجرت</td>
            </tr>
            <tr>
                <th>در انتظار</th>
                <td>
                    <?php if (($status['pending'] ?? []) === []): ?>
                        موردی نیست — نصب به‌روز است.
                    <?php else: ?>
                        <?php foreach ($status['pending'] as $name): ?>
                            <div><code><?= $e($name) ?></code></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>تنظیمات ذخیره‌شده</th>
                <td><?= $e((string) count($stored)) ?> کلید</td>
            </tr>
            <tr>
                <th>نیازمندی‌های الزامی برقرارنشده</th>
                <td><?= $e((string) ($requirements['required_failed'] ?? 0)) ?></td>
            </tr>
            </tbody>
        </table>
    </section>

    <section class="card stack">
        <h2>کارها</h2>
        <div class="stack-sm">
            <form method="post" action="<?= $e(($base ?? '') . '/maintenance') ?>" class="stack-sm">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="migrate">
                <button class="btn btn-primary" type="submit">اعمال مهاجرت‌ها (و قفل کردن صفحه)</button>
                <p class="field-help">
                    مهاجرت‌ها فقط-جلورو هستند و تکرارشان بی‌خطر است؛ اگر چیزی در انتظار نباشد، تغییری
                    نمی‌دهد. توجه: اگر مهاجرت‌ها را نمی‌خواهید اجرا کنید، برای قفل‌کردن صفحه از دکمه‌ی
                    «قفل کردن» استفاده کنید.
                </p>
            </form>

            <form method="post" action="<?= $e(($base ?? '') . '/maintenance') ?>" class="stack-sm">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="seed">
                <button class="btn btn-secondary" type="submit">افزودن تنظیمات پیش‌فرض تازه</button>
                <p class="field-help">
                    فقط کلیدهایی که هنوز وجود ندارند اضافه می‌شوند؛ مقدارهایی که خودتان تغییر داده‌اید
                    دست‌نخورده می‌مانند.
                </p>
            </form>

            <form method="post" action="<?= $e(($base ?? '') . '/maintenance') ?>" class="stack-sm">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="lock">
                <button class="btn btn-danger" type="submit">قفل کردن این صفحه</button>
                <p class="field-help">توکن فایل مصرف می‌شود و تا توکن تازه‌ای ساخته نشود، این صفحه کاری نمی‌کند.</p>
            </form>
        </div>
    </section>

    <section class="card stack">
        <h2>مسیر پوشه‌ی وضعیت</h2>
        <p>
            نشست‌ها، لاگ‌ها و صف در <code><?= $e($storage_path ?? 'storage') ?></code> هستند. این پوشه باید
            نوشتن‌پذیر باشد و بیرون از وب‌ریشه بماند.
        </p>
        <p class="text-muted text-sm">
            کران یادتان نرود: بدون آن، صف کار اجرا نمی‌شود. دستور و توضیح کامل در رانبوک نصب
            (<code>docs/engineering/runbooks/install-and-deploy.md</code>) آمده است.
        </p>
    </section>
<?php endif; ?>
