<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class NinCipher
{
    public static function normalize(string $nin): string
    {
        return preg_replace('/\D/', '', $nin) ?? '';
    }

    public static function hash(string $nin): string
    {
        $normalized = self::normalize($nin);

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public static function encrypt(string $nin): string
    {
        return Crypt::encryptString(self::normalize($nin));
    }

    public static function decrypt(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{11}$/', $value)) {
            return $value;
        }
        try {
            $plain = Crypt::decryptString($value);

            return $plain !== '' ? $plain : null;
        } catch (DecryptException) {
            return $value;
        }
    }

    public static function isPlain(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d{11}$/', trim($value));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sealPayload(array $payload): array
    {
        if (self::isPlain($payload['nin'] ?? null)) {
            $payload['nin'] = self::encrypt((string) $payload['nin']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function openPayload(array $payload): array
    {
        if (! empty($payload['nin']) && is_string($payload['nin'])) {
            $payload['nin'] = self::decrypt($payload['nin']) ?? $payload['nin'];
        }

        return $payload;
    }

    public static function redact(?string $nin): string
    {
        $plain = self::isPlain($nin) ? trim((string) $nin) : self::decrypt($nin);
        if (! $plain || strlen($plain) < 4) {
            return '***********';
        }

        return str_repeat('*', max(0, strlen($plain) - 3)).substr($plain, -3);
    }
}
