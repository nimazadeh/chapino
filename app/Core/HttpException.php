<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An error that is safe to show to the caller.
 *
 * Anything else (unexpected exception) becomes a generic Persian 500 with a request id,
 * and its details go to the log only. See the security rule: never leak internals.
 */
final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $userMessage,
        public readonly array $fields = [],
    ) {
        parent::__construct($userMessage);
    }

    /** @param array<string, string> $fields */
    public static function validation(array $fields, string $message = 'اطلاعات ارسالی معتبر نیست.'): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }

    public static function notFound(string $message = 'موردی که درخواست کردید پیدا نشد.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function unauthorized(string $message = 'برای انجام این کار باید وارد حساب خود شوید.'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'اجازه انجام این کار را ندارید.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function tooManyRequests(string $message = 'تلاش‌های شما بیش از حد مجاز بود. کمی بعد دوباره امتحان کنید.'): self
    {
        return new self(429, 'rate_limited', $message);
    }

    public static function methodNotAllowed(string $message = 'این درخواست با این روش پشتیبانی نمی‌شود.'): self
    {
        return new self(405, 'method_not_allowed', $message);
    }

    public static function serverError(string $message = 'خطای غیرمنتظره‌ای رخ داد. لطفاً بعداً دوباره تلاش کنید.'): self
    {
        return new self(500, 'server_error', $message);
    }
}
