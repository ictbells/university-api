<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PhoneNumber implements ValidationRule
{
    public const MESSAGE = 'Enter a valid Nigerian or international phone number (e.g. 0803 123 4567 or +1 202 555 0100).';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(self::MESSAGE);

            return;
        }
        if (trim($value) === '') {
            return;
        }
        if (self::normalize($value) === null) {
            $fail(self::MESSAGE);
        }
    }

    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/', '', $raw) ?? '';
        if ($compact === '') {
            return null;
        }
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (preg_match('/^0\d{10}$/', $compact)) {
            return '+234'.substr($compact, 1);
        }
        if (preg_match('/^2340\d{10}$/', $compact)) {
            return '+234'.substr($compact, 4);
        }
        if (preg_match('/^234\d{10}$/', $compact)) {
            return '+'.$compact;
        }
        if (preg_match('/^\+2340\d{10}$/', $compact)) {
            return '+234'.substr($compact, 5);
        }
        if (preg_match('/^\+234\d{10}$/', $compact)) {
            return $compact;
        }
        if (preg_match('/^\+[1-9]\d{7,14}$/', $compact)) {
            if (str_starts_with($compact, '+234')) {
                return null;
            }

            return $compact;
        }

        return null;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /**
     * @return list<string|self>
     */
    public static function constraints(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:30',
            new self,
        ];
    }

    public static function importValue(?string $raw, string $label = 'phone number'): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            throw new \RuntimeException('Enter a valid Nigerian or international '.$label.'.');
        }

        return $normalized;
    }
}
