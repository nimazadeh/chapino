<?php

declare(strict_types=1);

/**
 * The style guide: every component in its states, rendered from the real stylesheets.
 *
 * Why a page and not a document: a design system that is only described drifts from the one that is
 * used. This page is the reference the next phases copy from, and the test suite asserts that every
 * class it lists exists in `components.css` - so a component cannot be renamed in the stylesheet
 * without this page failing.
 *
 * Development only: the route refuses to serve it when `app.env` is `production`.
 *
 * The few inline `style` attributes below are a deliberate exception to "no inline styles": their
 * whole purpose is to display the *value* of a token, which cannot be written in a stylesheet without
 * hard-coding the very thing the page demonstrates. They never carry behaviour.
 *
 * Data contract:
 *   array  $tokenGroups  colour/spacing samples to display
 *   array  $fieldErrors  field name => Persian message, after a form submission
 *   array  $values       submitted values, redisplayed so nothing the user typed is lost
 *   ?array $submitted    the accepted submission, shown as a success state
 *
 * @var App\Core\View $view
 */

use App\Core\View;

$errors = $fieldErrors ?? [];
$values = $values ?? [];
$value = static fn (string $key): string => (string) ($values[$key] ?? '');
?>

<section class="stack">
    <h1 class="page-title">سیستم طراحی</h1>
    <p class="lead">
        مرجع بصری محصول: نشانه‌های رنگ، فاصله، تایپوگرافی فارسی و اجزای پایه - همه از فایل‌های واقعی
        <code>public/assets/css</code>. این صفحه فقط در محیط توسعه سرو می‌شود.
    </p>
</section>

<section class="stack" aria-labelledby="form-states">
    <h2 id="form-states">فرم و وضعیت‌های اعتبارسنجی</h2>
    <p class="text-muted">
        یک فرم واقعی: توکن CSRF در همان صفحه قرار می‌گیرد، خطاها زیر فیلد و با پیام فارسی نشان داده می‌شوند و
        مقادیر واردشده پس از خطا از دست نمی‌روند.
    </p>

    <?php if (isset($submitted) && $submitted !== null): ?>
        <div class="alert alert-success" role="status">
            <div>
                <strong>ثبت شد.</strong>
                <p>
                    نام: <?= View::e($submitted['full_name']) ?> —
                    موبایل: <span class="text-latin-numerals" dir="ltr"><?= View::e($submitted['mobile']) ?></span> —
                    تعداد: <span data-persian-number="<?= View::e($submitted['quantity']) ?>"><?= View::e($submitted['quantity']) ?></span>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <div>
                <strong>فرم کامل نیست.</strong>
                <p>موارد مشخص‌شده را اصلاح کنید و دوباره ثبت کنید.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php $token = $csrfToken ?? ''; ?>
    <form method="post" action="<?= View::e(($base ?? '') . '/design-system') ?>" novalidate>
        <input type="hidden" name="_token" value="<?= View::e($token) ?>">

        <div class="field">
            <label class="field-label field-required" for="full_name">نام و نام خانوادگی</label>
            <input
                class="field-input"
                id="full_name"
                name="full_name"
                type="text"
                value="<?= View::e($value('full_name')) ?>"
                <?= isset($errors['full_name']) ? 'aria-invalid="true" aria-describedby="full_name-error"' : '' ?>>
            <?php if (isset($errors['full_name'])): ?>
                <p class="field-error" id="full_name-error"><?= View::e($errors['full_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field-label field-required" for="mobile">شماره موبایل</label>
            <input
                class="field-input"
                id="mobile"
                name="mobile"
                type="tel"
                dir="ltr"
                inputmode="numeric"
                autocomplete="tel"
                placeholder="09120000000"
                value="<?= View::e($value('mobile')) ?>"
                <?= isset($errors['mobile']) ? 'aria-invalid="true" aria-describedby="mobile-help mobile-error"' : 'aria-describedby="mobile-help"' ?>>
            <p class="field-help" id="mobile-help">شماره را با پیش‌شماره‌ی محلی وارد کنید. نمونه: ۰۹۱۲۰۰۰۰۰۰۰</p>
            <?php if (isset($errors['mobile'])): ?>
                <p class="field-error" id="mobile-error"><?= View::e($errors['mobile']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field-label field-required" for="quantity">تعداد</label>
            <input
                class="field-input"
                id="quantity"
                name="quantity"
                type="number"
                min="1"
                max="10"
                dir="ltr"
                inputmode="numeric"
                value="<?= View::e($value('quantity')) ?>"
                <?= isset($errors['quantity']) ? 'aria-invalid="true" aria-describedby="quantity-error"' : '' ?>>
            <?php if (isset($errors['quantity'])): ?>
                <p class="field-error" id="quantity-error"><?= View::e($errors['quantity']) ?></p>
            <?php endif; ?>
        </div>

        <div class="cluster">
            <button class="btn" type="submit">ثبت نمونه</button>
            <button class="btn btn-secondary" type="reset">پاک کردن</button>
            <button class="btn btn-ghost" type="button" disabled>دکمه‌ی غیرفعال</button>
        </div>
    </form>
</section>

<section class="stack" aria-labelledby="typography">
    <h2 id="typography">تایپوگرافی فارسی</h2>
    <div class="card stack-sm">
        <p class="text-sm">متن کوچک — برای توضیح‌های فرعی و فراداده‌ی جدول‌ها.</p>
        <p>متن معمولی — اندازه‌ی پایه‌ی خواندن در موبایل، هرگز کوچک‌تر از این مقدار استفاده نمی‌شود.</p>
        <p class="lead">متن مقدمه — کمی بزرگ‌تر، برای توضیح بالای صفحه.</p>
        <h3>سرتیتر سوم</h3>
        <p>
            نیم‌فاصله درست نوشته می‌شود: می‌شود، کتاب‌ها، بی‌خودی. متن دوجهته هم آزمایش می‌شود:
            شناسه‌ی <bdi class="text-latin-numerals">CHP-2026-0001</bdi> در میان جمله‌ی فارسی باید بدون
            جابه‌جایی پرانتز و اسلش نمایش داده شود.
        </p>
    </div>
</section>

<section class="stack" aria-labelledby="numbers">
    <h2 id="numbers">اعداد و مبالغ</h2>
    <p class="text-muted">
        ارقام نمایش‌داده‌شده فارسی‌اند و ارقام لاتین برای شناسه‌ها و مقادیر سامانه‌ای نگه داشته می‌شوند.
    </p>
    <div class="card cluster">
        <span class="badge badge-brand" data-persian-number="1250000">1250000</span>
        <span class="badge" data-persian-number="0">0</span>
        <span class="badge badge-success" data-persian-number="1234500">1234500</span>
        <span class="text-latin-numerals" dir="ltr">CHP-2026-0001</span>
    </div>
</section>

<section class="stack" aria-labelledby="colours">
    <h2 id="colours">رنگ‌ها</h2>
    <div class="cluster">
        <?php foreach ($tokenGroups['colour'] ?? [] as $name => $token): ?>
            <div class="card stack-sm" style="min-width: 12rem">
                <span class="badge"><?= View::e($name) ?></span>
                <div style="background: var(<?= View::e($token) ?>); height: 3rem; border-radius: var(--radius-md); border: var(--border-width) solid var(--color-border)"></div>
                <code class="text-sm"><?= View::e($token) ?></code>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="stack" aria-labelledby="spacing">
    <h2 id="spacing">فاصله‌ها</h2>
    <div class="card">
        <?php foreach ($tokenGroups['space'] ?? [] as $token): ?>
            <div class="cluster stack-sm">
                <code class="text-sm" style="min-width: 9rem"><?= View::e($token) ?></code>
                <span style="display: inline-block; height: 0.75rem; width: var(<?= View::e($token) ?>); background: var(--color-brand-500); border-radius: var(--radius-sm)"></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="stack" aria-labelledby="components">
    <h2 id="components">اجزای پایه</h2>

    <div class="card stack">
        <h3>دکمه‌ها</h3>
        <div class="cluster">
            <button class="btn" type="button">دکمه‌ی اصلی</button>
            <button class="btn btn-secondary" type="button">دکمه‌ی دوم</button>
            <button class="btn btn-ghost" type="button">دکمه‌ی کم‌رنگ</button>
            <button class="btn btn-danger" type="button">دکمه‌ی خطر</button>
            <button class="btn btn-sm btn-secondary" type="button">دکمه‌ی کوچک</button>
            <button class="btn" type="button" disabled aria-busy="true">
                <span class="spinner" aria-hidden="true"></span>
                در حال ارسال
            </button>
        </div>
    </div>

    <div class="card stack">
        <h3>وضعیت‌های فیلد</h3>
        <div class="field">
            <label class="field-label" for="field-ok">فیلد سالم</label>
            <input class="field-input" id="field-ok" type="text" value="مقدار نمونه" aria-describedby="field-ok-help">
            <p class="field-help" id="field-ok-help">راهنمای زیر فیلد، همیشه پیش از خطا نشان داده می‌شود.</p>
        </div>
        <div class="field">
            <label class="field-label field-required" for="field-bad">فیلد نامعتبر</label>
            <input class="field-input" id="field-bad" type="text" value="н" aria-invalid="true" aria-describedby="field-bad-error">
            <p class="field-error" id="field-bad-error">این مقدار معتبر نیست؛ نمونه‌ی درست را وارد کنید.</p>
        </div>
        <div class="field">
            <label class="field-label" for="field-disabled">فیلد غیرفعال</label>
            <input class="field-input" id="field-disabled" type="text" value="قابل تغییر نیست" disabled>
        </div>
    </div>

    <div class="card stack">
        <h3>پیام‌ها</h3>
        <div class="alert alert-info" role="status">اطلاع‌رسانی: کارهای پس‌زمینه هر دقیقه یک بار اجرا می‌شوند.</div>
        <div class="alert alert-success" role="status">انجام شد: طرح شما ذخیره شد.</div>
        <div class="alert alert-warning" role="status">هشدار: پیش‌نویس شما فقط روی همین دستگاه ذخیره شده است.</div>
        <div class="alert alert-danger" role="alert">خطا: ارتباط با سرور برقرار نشد. دوباره تلاش کنید.</div>
    </div>

    <div class="card stack">
        <h3>نشان‌ها</h3>
        <div class="cluster">
            <span class="badge">پیش‌نویس</span>
            <span class="badge badge-brand">در حال طراحی</span>
            <span class="badge badge-success">پرداخت‌شده</span>
            <span class="badge badge-warning">در انتظار بررسی</span>
            <span class="badge badge-danger">لغوشده</span>
        </div>
    </div>

    <div class="card stack">
        <h3>جدول</h3>
        <div class="table-wrapper">
            <table class="table">
                <caption>نمونه‌ی جدول سفارش‌ها — ستون عددی برای مقایسه‌ی راحت‌تر، تراز لاتین دارد.</caption>
                <thead>
                    <tr>
                        <th scope="col">شناسه</th>
                        <th scope="col">وضعیت</th>
                        <th scope="col" class="cell-number">مبلغ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td dir="ltr" class="text-latin-numerals">CHP-2026-0001</td>
                        <td><span class="badge badge-success">پرداخت‌شده</span></td>
                        <td class="cell-number" data-persian-number="480000">480000</td>
                    </tr>
                    <tr>
                        <td dir="ltr" class="text-latin-numerals">CHP-2026-0002</td>
                        <td><span class="badge badge-warning">در انتظار بررسی</span></td>
                        <td class="cell-number" data-persian-number="1200000">1200000</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card stack">
        <h3>حالت خالی و بارگذاری</h3>
        <div class="empty-state">
            <p class="empty-state-title">هنوز طرحی نساخته‌اید</p>
            <p class="text-muted">با ساخت اولین طرح، این فهرست پر می‌شود.</p>
            <button class="btn" type="button" disabled>ساخت طرح (در گام بعدی)</button>
        </div>
        <p class="cluster">
            <span class="spinner" aria-hidden="true"></span>
            <span>در حال بارگذاری…</span>
        </p>
    </div>
</section>
