<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\HttpException;

/**
 * Server-side validation. The only validation that is authoritative (see the security rule).
 *
 * Usage:
 *   $data = Validator::make($request->allInput(), [
 *       'mobile' => 'required|iran_mobile',
 *       'full_name' => 'required|string|min:3|max:100',
 *       'quantity' => 'required|integer|min:1|max:10',
 *   ])->validate();
 *
 * Rules are written explicitly rather than magically discovered, and every failure
 * message is Persian.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules field => "rule1|rule2:arg"
     * @param array<string, string> $labels field => Persian label for messages
     */
    public function __construct(
        private readonly array $input,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    public static function make(array $input, array $rules, array $labels = []): self
    {
        return new self($input, $rules, $labels);
    }

    /** @return array<string, mixed> validated values only */
    public function validate(): array
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->input[$field] ?? null;
            $ruleList = explode('|', $ruleString);
            $isRequired = in_array('required', $ruleList, true);
            $isNullable = in_array('nullable', $ruleList, true);
            $isEmpty = $this->isEmpty($value);

            if ($isEmpty && !$isRequired) {
                // Optional and absent: the key is simply not part of the validated payload.
                continue;
            }

            if ($isEmpty && $isRequired && !$isNullable) {
                $this->errors[$field] = $this->message('required', $field);
                continue;
            }

            if ($isEmpty && $isRequired && $isNullable) {
                continue;
            }

            $normalized = $this->applyRules($field, $value, $ruleList);
            if (isset($this->errors[$field])) {
                continue;
            }
            if ($normalized !== null) {
                $this->validated[$field] = $normalized;
            }
        }

        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }

        return $this->validated;
    }

    /** @param list<string> $ruleList */
    private function applyRules(string $field, mixed $value, array $ruleList): mixed
    {
        // Normalization happens BEFORE the rules run: the value the rules judge must be
        // the value that will be stored (Persian digits are digits, and an Arabic yeh is
        // the same letter as a Persian yeh). See the localization rule.
        $current = is_string($value) ? PersianText::normalize($value) : $value;

        foreach ($ruleList as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            $arguments = $argument === null ? [] : explode(',', $argument);

            switch ($name) {
                case 'required':
                case '':
                    break;

                case 'string':
                    if (!is_string($current)) {
                        $this->errors[$field] = $this->message('string', $field);
                        return null;
                    }
                    break;

                case 'integer':
                case 'int':
                    if (is_string($current) && preg_match('/^-?\d+$/', $current) === 1) {
                        $current = (int) $current;
                    }
                    if (!is_int($current)) {
                        $this->errors[$field] = $this->message('integer', $field);
                        return null;
                    }
                    break;

                case 'numeric':
                    if (!is_numeric($current)) {
                        $this->errors[$field] = $this->message('numeric', $field);
                        return null;
                    }
                    break;

                case 'boolean':
                case 'bool':
                    $normalized = filter_var($current, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($normalized === null) {
                        $this->errors[$field] = $this->message('boolean', $field);
                        return null;
                    }
                    $current = $normalized;
                    break;

                case 'array':
                    if (!is_array($current)) {
                        $this->errors[$field] = $this->message('array', $field);
                        return null;
                    }
                    break;

                case 'in':
                    if (!in_array((string) $current, $arguments, true)) {
                        $this->errors[$field] = $this->message('in', $field);
                        return null;
                    }
                    break;

                case 'min':
                    $limit = (int) ($arguments[0] ?? 0);
                    if (is_int($current) || is_float($current)) {
                        if ($current < $limit) {
                            $this->errors[$field] = $this->message('min_number', $field, $limit);
                            return null;
                        }
                    } elseif (!is_array($current) && mb_strlen((string) $current) < $limit) {
                        $this->errors[$field] = $this->message('min_string', $field, $limit);
                        return null;
                    }
                    break;

                case 'max':
                    $limit = (int) ($arguments[0] ?? 0);
                    if (is_int($current) || is_float($current)) {
                        if ($current > $limit) {
                            $this->errors[$field] = $this->message('max_number', $field, $limit);
                            return null;
                        }
                    } elseif (mb_strlen((string) $current) > $limit) {
                        $this->errors[$field] = $this->message('max_string', $field, $limit);
                        return null;
                    }
                    break;

                case 'email':
                    if (!filter_var((string) $current, FILTER_VALIDATE_EMAIL)) {
                        $this->errors[$field] = $this->message('email', $field);
                        return null;
                    }
                    break;

                case 'iran_mobile':
                    $normalized = PersianText::normalizeDigits((string) $current);
                    if (!PersianText::isIranianMobile($normalized)) {
                        $this->errors[$field] = $this->message('iran_mobile', $field);
                        return null;
                    }
                    $current = PersianText::canonicalMobile($normalized);
                    break;

                case 'iran_postal_code':
                    if (!PersianText::isIranianPostalCode(PersianText::normalizeDigits((string) $current))) {
                        $this->errors[$field] = $this->message('iran_postal_code', $field);
                        return null;
                    }
                    break;

                case 'iran_national_id':
                    if (!PersianText::isIranianNationalId(PersianText::normalizeDigits((string) $current))) {
                        $this->errors[$field] = $this->message('iran_national_id', $field);
                        return null;
                    }
                    break;

                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $current)) {
                        $this->errors[$field] = $this->message('date', $field);
                        return null;
                    }
                    break;

                case 'url':
                    if (filter_var((string) $current, FILTER_VALIDATE_URL) === false) {
                        $this->errors[$field] = $this->message('url', $field);
                        return null;
                    }
                    break;

                case 'id':
                    if (!is_string($current) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $current) !== 1) {
                        $this->errors[$field] = $this->message('id', $field);
                        return null;
                    }
                    break;

                case 'sometimes':
                case 'nullable':
                    break;

                default:
                    throw new \LogicException("Unknown validation rule: {$name}");
            }
        }

        return $current;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function message(string $type, string $field, int|string|null $limit = null): string
    {
        $label = $this->labels[$field] ?? $field;

        return match ($type) {
            'required' => "{$label} را وارد کنید.",
            'string' => "{$label} باید متن باشد.",
            'integer' => "{$label} باید عدد صحیح باشد.",
            'numeric' => "{$label} باید عدد باشد.",
            'boolean' => "{$label} باید بله یا خیر باشد.",
            'array' => "{$label} باید فهرست باشد.",
            'in' => "مقدار {$label} مجاز نیست.",
            'email' => "{$label} معتبر نیست.",
            'iran_mobile' => "{$label} باید یک شماره موبایل معتبر ایران باشد. نمونه: ۰۹۱۲۰۰۰۰۰۰۰",
            'iran_postal_code' => "{$label} باید کد پستی ۱۰ رقمی معتبر ایران باشد.",
            'iran_national_id' => "{$label} باید کد ملی ۱۰ رقمی معتبر ایران باشد.",
            'date' => "{$label} باید تاریخ معتبر با قالب ۱۳۷۰/۰۱/۰۱ باشد.",
            'url' => "{$label} باید نشانی اینترنتی معتبر باشد.",
            'id' => "شناسه {$label} معتبر نیست.",
            'min_string' => "{$label} باید حداقل {$limit} نویسه باشد.",
            'max_string' => "{$label} نباید بیشتر از {$limit} نویسه باشد.",
            'min_number' => "{$label} نباید کمتر از {$limit} باشد.",
            'max_number' => "{$label} نباید بیشتر از {$limit} باشد.",
            default => "{$label} معتبر نیست.",
        };
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Collects failures without throwing, for callers that need to inspect them
     * (for example a multi-step form). Prefer validate() everywhere else.
     *
     * @return array<string, mixed> validated values (empty when invalid)
     */
    public function validateOrFail(): array
    {
        try {
            return $this->validate();
        } catch (HttpException) {
            return [];
        }
    }

    public function isValid(): bool
    {
        try {
            $this->validate();

            return true;
        } catch (HttpException) {
            return false;
        }
    }
}
