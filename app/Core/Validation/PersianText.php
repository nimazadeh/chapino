<?php

declare(strict_types=1);

namespace App\Core\Validation;

/**
 * Persian text and Iranian data formats.
 *
 * The product is Persian-only and used in Iran (C-1, C-2, C-5), so this is core
 * domain behaviour, not a formatting detail: user input arrives with Arabic yeh/kaf,
 * Persian and Arabic-Indic digits, and several half-space variants of the same word.
 * Normalizing in one place is what makes "search finds what the user typed" possible.
 *
 * See the localization rule. Jalali date conversion is intentionally NOT here yet:
 * it arrives with the screens that display dates, and must be implemented by a
 * reviewed, unit-tested converter rather than by hand arithmetic.
 */
final class PersianText
{
    /** Half-space (U+200C) and the characters users actually type instead of it. */
    private const ZWNJ = "\u{200C}";

    /**
     * Canonical text normalization: Persian characters, digits, spacing.
     *
     * This runs on every validated string, so it must be idempotent: normalizing an
     * already-normalized value changes nothing.
     */
    public static function normalize(string $value): string
    {
        // Arabic letter forms that Persian writers type by accident.
        $value = str_replace(['ي', 'ى', 'ﻯ', 'ﻰ'], 'ی', $value);
        $value = str_replace(['ك', 'ﻙ'], 'ک', $value);
        $value = str_replace(['ة'], 'ه', $value);
        $value = str_replace(['ۀ'], 'ه‌', $value);

        // Remove the tatweel used for stretching, and Arabic diacritics.
        $value = str_replace("\u{0640}", '', $value);
        $value = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $value);

        // Unify the various space-like characters into a normal space, then keep ZWNJ.
        $value = (string) preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $value);

        // Convert digits to the storage convention (ASCII), so comparisons and sorting
        // are predictable. Presentation converts back to Persian digits.
        $value = self::normalizeDigits($value);

        // Collapse runs of spaces and trim.
        $value = (string) preg_replace('/ {2,}/u', ' ', $value);

        return trim($value);
    }

    /** Persian (۰-۹) and Arabic-Indic (٠-٩) digits to ASCII digits. */
    public static function normalizeDigits(string $value): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace(array_merge($persian, $arabic), array_merge($latin, $latin), $value);
    }

    /** ASCII digits to Persian digits, for display. */
    public static function toPersianDigits(string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $value,
        );
    }

    /** Formats an integer with the Persian thousands separator. */
    public static function formatNumber(int|float|string $value, bool $persianDigits = true): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        $isWhole = abs($number - round($number)) < 0.0000001;
        $formatted = number_format($number, $isWhole ? 0 : 2, '٫', '٬');

        return $persianDigits ? self::toPersianDigits($formatted) : $formatted;
    }

    /**
     * Iranian mobile numbers: 09xxxxxxxxx, +989xxxxxxxxx, 00989xxxxxxxxx, 989xxxxxxxxx,
     * and the same written with Persian digits. Returns the canonical 09xxxxxxxxx form,
     * or null when the value is not a valid Iranian mobile number.
     */
    public static function canonicalMobile(string $value): ?string
    {
        $digits = self::normalizeDigits($value);
        $digits = (string) preg_replace('/[^0-9+]/', '', $digits);

        if (str_starts_with($digits, '+98')) {
            $digits = '0' . substr($digits, 3);
        } elseif (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (preg_match('/^9\d{9}$/', $digits) === 1) {
            $digits = '0' . $digits;
        } elseif (preg_match('/^98\d{10}$/', $digits) === 1) {
            $digits = '0' . substr($digits, 2);
        }

        return self::isIranianMobile($digits) ? $digits : null;
    }

    public static function isIranianMobile(string $value): bool
    {
        $digits = self::normalizeDigits($value);
        if (preg_match('/^09\d{9}$/', $digits) !== 1) {
            return false;
        }

        // Iranian mobile prefixes: 090x..099x are valid ranges.
        return preg_match('/^09[0-9]\d{8}$/', $digits) === 1;
    }

    public static function isIranianPostalCode(string $value): bool
    {
        $digits = self::normalizeDigits($value);
        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            return false;
        }

        // A postal code may not consist of one repeated digit.
        return preg_match('/^(\d)\1{9}$/', $digits) !== 1;
    }

    /**
     * Iranian national ID (کد ملی): exactly 10 digits with the official check digit.
     * The value is validated, never guessed: an invalid check digit is rejected.
     */
    public static function isIranianNationalId(string $value): bool
    {
        $digits = self::normalizeDigits($value);
        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            return false;
        }
        if (preg_match('/^(\d)\1{9}$/', $digits) === 1) {
            return false; // 1111111111 and similar are structurally invalid
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $digits[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $digits[9];

        return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
    }

    /** Joins a number and its unit with a half-space, e.g. ۳ عدد. */
    public static function withUnit(string $value, string $unit): string
    {
        return $value . self::ZWNJ . $unit;
    }
}
