<?php

declare(strict_types=1);

/**
 * The document shell every page uses.
 *
 * Data contract (all optional except `title` and `content`):
 *   string  $title        page title, escaped and suffixed with the site name
 *   string  $content      already-rendered HTML of the page body
 *   string  $base         deployment prefix from Request::basePath()
 *   ?string $csrfToken    when present, emitted as a meta tag for the script layer
 *   string  $bodyClass    extra classes for <body>
 *   array   $styles       page-level stylesheets, appended after the four the layout itself needs
 *
 * The document declares `lang="fa"` and `dir="rtl"` once, at the root element (localization rule):
 * direction is a property of the document, not something each screen re-states.
 *
 * @var App\Core\View $view
 */

use App\Core\Asset;
use App\Core\View;

$asset = new Asset(APP_ROOT . '/public');
$pageTitle = ($title ?? '') === '' ? 'چاپینو' : $title . ' | چاپینو';
$base = rtrim((string) ($base ?? ''), '/');
/*
 * The four sheets of the design system, in load order: tokens, base, layout, components. The shell
 * (header, main, footer) is part of THIS template, so its stylesheet belongs to the template's own
 * defaults - a page that had to remember `layout.css` would one day forget it and ship an unstyled
 * shell. Page-level styles arrive through $styles and therefore win over the base components.
 */
$styles = array_merge(
    [
        'assets/css/tokens.css',
        'assets/css/base.css',
        'assets/css/layout.css',
        'assets/css/components.css',
    ],
    $styles ?? [],
);
?>
<!doctype html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($pageTitle) ?></title>
    <?php if (!empty($csrfToken)): ?>
        <?php /* Present only on pages that contain a form, so an anonymous page view costs no session. */ ?>
        <meta name="csrf-token" content="<?= View::e($csrfToken) ?>">
    <?php endif; ?>
    <?php foreach ($styles as $style): ?>
        <link rel="stylesheet" href="<?= View::e($asset->url($base, $style)) ?>">
    <?php endforeach; ?>
    <script src="<?= View::e($asset->url($base, 'assets/js/app.js')) ?>" defer></script>
</head>

<body<?= isset($bodyClass) ? ' class="' . View::e($bodyClass) . '"' : '' ?>>
    <a class="skip-link" href="#main">پرش به محتوای اصلی</a>

    <header class="site-header">
        <div class="container cluster cluster-between">
            <a class="site-brand" href="<?= View::e($base === '' ? '/' : $base . '/') ?>">چاپینو</a>
            <nav aria-label="ناوبری اصلی">
                <ul class="nav-list">
                    <li><a href="<?= View::e($base === '' ? '/' : $base . '/') ?>">خانه</a></li>
                    <li><a href="<?= View::e($base . '/api/health') ?>">وضعیت سامانه</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main id="main" class="container page">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container text-sm text-muted">
            <p>چاپینو — سامانه‌ی سفارش و طراحی چاپ در حال ساخت است.</p>
        </div>
    </footer>
</body>

</html>
