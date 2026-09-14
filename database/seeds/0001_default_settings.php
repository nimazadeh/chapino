<?php

declare(strict_types=1);

/**
 * Default settings for a fresh installation.
 *
 * What belongs here: facts the *operator* owns and changes in normal operation, where a wrong value
 * is a wrong display or a wrong print, not a broken server.
 *
 * What deliberately does NOT belong here, and where it lives instead:
 *
 *  - **Providers and their credentials** (`sms.provider`, `payment.provider`, `ai.provider`, API keys,
 *    merchant id) live in `config/config.php`, next to the secrets they belong to. A provider name in
 *    a database row while its key sits in a file is two facts that drift apart.
 *  - **Server-protection limits** (`security.rate_limits.anonymous_write_per_hour`, and the OTP
 *    quotas that `O-6` requires) live in `config/config.php` as well, because they protect the server
 *    itself: a limit that can be relaxed from the panel is a limit an attacker with a stolen admin
 *    session can relax. Where they live may be revisited when the admin panel exists (Phase 5); it
 *    must not be duplicated in both places before then.
 *  - **The display name** (`site.name`) arrives with the settings panel that reads it. Seeding a key
 *    nothing reads yet would create a value that looks authoritative and does nothing.
 *
 * Three groups, because they do not carry the same authority:
 *
 *  1. **Ratified decisions** - the owner already decided these (`C-13`, `O-6`, `O-14`), so the code
 *     and the database agree from the first request.
 *  2. **Owner input required** - seeded with an EMPTY value and `owner_input => true`. These are
 *     decisions about money, so no agent may invent them; every consumer must treat an empty value as
 *     "not configured yet" and refuse to guess.
 *
 * Nothing here is a secret.
 *
 * Rules for editing this file (enforced by {@see \App\Core\Database\Seeder}):
 *  - keys are lower-case dotted namespaces and must be unique across every seed file;
 *  - a value is only written when the key is absent, so an operator's edit is never overwritten;
 *  - changing a value here does NOT update installations that already hold the key - that is what
 *    migrations are for, and pretending otherwise is how live data gets clobbered.
 *
 * Created by: Phase 0, slice 0.5.
 */

return [
    // --- 1. Ratified decisions --------------------------------------------------------------

    // O-6: mobile number + one-time code via Kaveh Negar, no passwords anywhere.
    'auth.method' => [
        'value' => 'mobile_otp',
        'note' => 'O-6: ورود با شماره موبایل و رمز یک‌بارمصرف. رمز عبور در این محصول وجود ندارد.',
    ],

    // C-13: the AI entry point is visible in the interface and disabled.
    'features.ai_button_visible' => [
        'value' => '1',
        'note' => 'C-13: دکمه‌ی هوش مصنوعی دیده می‌شود ولی غیرفعال است. خودِ فعال‌بودن با config (ai.enabled) کنترل می‌شود و در دامنه‌ی فعلی خاموش است (C-12).',
    ],

    // O-14: the print contract, ratified on 2026-09-14. Every value is configuration-driven, so the
    // print house's real numbers replace them without a code change.
    'print.product_type' => [
        'value' => 'tshirt',
        'note' => 'O-14: محصول نسخه‌ی اول فقط تی‌شرت است.',
    ],
    'print.area.front.width_cm' => ['value' => '30', 'note' => 'O-14: ناحیه‌ی چاپ جلو، عرض (سانتی‌متر).'],
    'print.area.front.height_cm' => ['value' => '40', 'note' => 'O-14: ناحیه‌ی چاپ جلو، ارتفاع (سانتی‌متر).'],
    'print.area.back.width_cm' => ['value' => '30', 'note' => 'O-14: ناحیه‌ی چاپ پشت، عرض (سانتی‌متر).'],
    'print.area.back.height_cm' => ['value' => '40', 'note' => 'O-14: ناحیه‌ی چاپ پشت، ارتفاع (سانتی‌متر).'],
    'print.area.sleeve.width_cm' => ['value' => '10', 'note' => 'O-14: ناحیه‌ی چاپ آستین، عرض (سانتی‌متر).'],
    'print.area.sleeve.height_cm' => ['value' => '10', 'note' => 'O-14: ناحیه‌ی چاپ آستین، ارتفاع (سانتی‌متر).'],
    'print.dpi' => [
        'value' => '300',
        'note' => 'O-14: رزولوشن خروجی چاپ. ۳۰۰ نقطه بر اینچ حد پایین کیفیت چاپ است، نه یک سلیقه.',
    ],
    'print.background' => [
        'value' => 'transparent_png',
        'note' => 'O-14: خروجی PNG با پس‌زمینه‌ی شفاف.',
    ],
    'print.color_space' => [
        'value' => 'rgb',
        'note' => 'O-14: فضای رنگی RGB؛ تبدیل به CMYK کار چاپخانه است و نباید در مرورگر حدس زده شود.',
    ],

    // --- 2. Owner input required (empty on purpose) ------------------------------------------

    'pricing.commission_percent' => [
        'value' => '',
        'owner_input' => true,
        'note' => 'O-15: مدل درآمدی ترکیبی تأیید شده است، اما درصد کمیسیون تصمیم شماست. تا زمانی که خالی باشد هیچ کدی نباید عددی را حدس بزند، و سامانه باید آن را «تعیین‌نشده» گزارش کند نه صفر.',
    ],
];
