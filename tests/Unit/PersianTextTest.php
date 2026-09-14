<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validation\PersianText;
use Tests\TestCase;

/**
 * Persian text and Iranian formats are product behaviour, not cosmetics (C-1, C-2, C-5):
 * a mobile number typed with Persian digits must be accepted, and a national ID with a
 * wrong check digit must be rejected.
 */
final class PersianTextTest extends TestCase
{
    public function testNormalizeConvertsArabicLetterFormsToPersian(): void
    {
        $this->assertSame('یک', PersianText::normalize('يك'), 'Arabic yeh must become Persian yeh');
        $this->assertSame('کتاب', PersianText::normalize('كتاب'), 'Arabic kaf must become Persian kaf');
    }

    public function testNormalizeIsIdempotent(): void
    {
        $input = 'نمونهٔ  متن يادداشت';
        $once = PersianText::normalize($input);
        $this->assertSame($once, PersianText::normalize($once), 'normalize() must be idempotent');
    }

    public function testNormalizeConvertsPersianAndArabicDigitsToLatin(): void
    {
        $this->assertSame('12345', PersianText::normalizeDigits('۱۲۳۴۵'));
        $this->assertSame('67890', PersianText::normalizeDigits('٦٧٨٩٠'));
    }

    public function testNormalizeRemovesTatweelAndCollapsesSpaces(): void
    {
        $this->assertSame('سلام دنیا', PersianText::normalize('سلامـــ  دنیا'));
    }

    public function testNormalizeKeepsZeroWidthNonJoiner(): void
    {
        $normalized = PersianText::normalize('می‌رود');
        $this->assertStringContains("\u{200C}", $normalized, 'the half-space must survive normalization');
    }

    public function testToPersianDigitsFormatsForDisplay(): void
    {
        $this->assertSame('۱۴۰۴/۰۶/۲۳', PersianText::toPersianDigits('1404/06/23'));
    }

    public function testFormatNumberUsesPersianSeparators(): void
    {
        $this->assertSame('۱٬۲۵۰٬۰۰۰', PersianText::formatNumber(1250000));
    }

    public function testCanonicalMobileAcceptsEveryCommonForm(): void
    {
        foreach (['09121234567', '۰۹۱۲۱۲۳۴۵۶۷', '+989121234567', '00989121234567', '989121234567', '0912 123 4567'] as $input) {
            $this->assertSame('09121234567', PersianText::canonicalMobile($input), "failed for input: {$input}");
        }
    }

    public function testCanonicalMobileRejectsInvalidNumbers(): void
    {
        foreach (['08121234567', '0912123456', '091212345678', '12345', '', 'abcdefghijk'] as $input) {
            $this->assertNull(PersianText::canonicalMobile($input), "should be rejected: {$input}");
        }
    }

    public function testIranianPostalCodeRules(): void
    {
        $this->assertTrue(PersianText::isIranianPostalCode('۱۹۹۳۷۶۴۵۹۱'));
        $this->assertFalse(PersianText::isIranianPostalCode('1111111111'), 'repeated digits are not a postal code');
        $this->assertFalse(PersianText::isIranianPostalCode('12345'), 'a postal code has 10 digits');
    }

    public function testIranianNationalIdChecksum(): void
    {
        // Check digit computed from the official algorithm, not copied from memory:
        // sum(0*10+0*9+1*8+3*7+5*6+4*5+1*4+7*3+9*2) = 122; 122 % 11 = 1; 1 < 2 so the
        // check digit must equal the remainder, i.e. 1.
        $this->assertTrue(PersianText::isIranianNationalId('۰۰۱۳۵۴۱۷۹۱'), 'valid check digit must be accepted');

        // Same base digits, wrong check digit.
        $this->assertFalse(PersianText::isIranianNationalId('0013541798'), 'wrong check digit must be rejected');
        $this->assertFalse(PersianText::isIranianNationalId('1111111111'));
        $this->assertFalse(PersianText::isIranianNationalId('001354179'));
    }
}
