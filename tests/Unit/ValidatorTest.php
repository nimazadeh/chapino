<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Validation\Validator;
use Tests\TestCase;

/**
 * Server-side validation is the only authoritative validation (see the security rule).
 * These tests cover the invalid path at least as thoroughly as the happy path.
 */
final class ValidatorTest extends TestCase
{
    private const LABELS = ['mobile' => 'شماره موبایل', 'full_name' => 'نام و نام خانوادگی'];

    public function testValidPayloadIsReturnedNormalized(): void
    {
        $data = Validator::make(
            ['mobile' => '۰۹۱۲۱۲۳۴۵۶۷', 'full_name' => '  علی   رضایی '],
            ['mobile' => 'required|iran_mobile', 'full_name' => 'required|string|min:3|max:100'],
            self::LABELS,
        )->validate();

        $this->assertSame('09121234567', $data['mobile'], 'mobile must be canonicalized');
        $this->assertSame('علی رضایی', $data['full_name'], 'text must be trimmed and normalized');
    }

    public function testMissingRequiredFieldFailsWithPersianMessage(): void
    {
        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make([], ['mobile' => 'required|iran_mobile'], self::LABELS)->validate(),
        );

        $this->assertSame(422, $exception->status);
        $this->assertSame('validation_failed', $exception->errorCode);
        $this->assertArrayHasKey('mobile', $exception->fields);
        $this->assertStringContains('شماره موبایل', $exception->fields['mobile']);
    }

    public function testInvalidMobileIsRejected(): void
    {
        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['mobile' => '12345'], ['mobile' => 'required|iran_mobile'], self::LABELS)->validate(),
        );

        $this->assertStringContains('موبایل', $exception->fields['mobile']);
    }

    public function testOptionalFieldMayBeAbsentAndIsNotIncludedInOutput(): void
    {
        $data = Validator::make(
            ['mobile' => '09121234567'],
            ['mobile' => 'required|iran_mobile', 'email' => 'email'],
        )->validate();

        $this->assertFalse(array_key_exists('email', $data), 'absent optional fields must not appear in the payload');
    }

    public function testOptionPassedValueIsStillValidated(): void
    {
        $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['email' => 'not-an-email'], ['email' => 'email'])->validate(),
        );
    }

    public function testBoundariesMinAndMaxForStrings(): void
    {
        $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['name' => 'ab'], ['name' => 'required|string|min:3'])->validate(),
        );
        $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['name' => 'abcd'], ['name' => 'required|string|max:3'])->validate(),
        );

        $data = Validator::make(['name' => 'abc'], ['name' => 'required|string|min:3|max:3'])->validate();
        $this->assertSame('abc', $data['name'], 'the exact boundary must be accepted');
    }

    public function testIntegerCoercionAndBounds(): void
    {
        $data = Validator::make(['quantity' => '۳'], ['quantity' => 'required|integer|min:1|max:5'])->validate();
        $this->assertSame(3, $data['quantity']);

        $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['quantity' => '6'], ['quantity' => 'required|integer|min:1|max:5'])->validate(),
        );
        $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['quantity' => '1.5'], ['quantity' => 'required|integer'])->validate(),
        );
    }

    public function testEnumMembership(): void
    {
        $data = Validator::make(['color' => 'white'], ['color' => 'required|in:white,black,pink'])->validate();
        $this->assertSame('white', $data['color']);

        $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(['color' => 'gold'], ['color' => 'required|in:white,black,pink'])->validate(),
        );
    }

    public function testAllErrorsAreReportedInOneResponse(): void
    {
        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => Validator::make(
                ['mobile' => '123', 'full_name' => ''],
                ['mobile' => 'required|iran_mobile', 'full_name' => 'required|string|min:3'],
                self::LABELS,
            )->validate(),
        );

        $this->assertCount(2, $exception->fields, 'the user must see every problem at once');
    }

    public function testUnknownRuleIsAProgrammingErrorNotASilentPass(): void
    {
        $this->assertThrows(
            \LogicException::class,
            static fn () => Validator::make(['x' => 'y'], ['x' => 'definitely_not_a_rule'])->validate(),
        );
    }
}
