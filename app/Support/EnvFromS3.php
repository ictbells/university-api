<?php

namespace App\Support;

class EnvFromS3
{
    public static function enabled(?string $value = null): bool
    {
        if ($value === null) {
            $fromProcess = getenv('LOAD_ENV_FROM_S3');
            $value = $fromProcess !== false
                ? $fromProcess
                : (string) ($_ENV['LOAD_ENV_FROM_S3'] ?? '');
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function bucket(): string
    {
        return self::read('ENV_S3_BUCKET') ?: self::read('AWS_BUCKET');
    }

    public static function key(): string
    {
        return self::read('ENV_S3_KEY') ?: 'api/.env';
    }

    public static function region(): string
    {
        return self::read('AWS_DEFAULT_REGION') ?: 'us-east-1';
    }

    public static function uri(?string $bucket = null, ?string $key = null): string
    {
        $bucket = $bucket ?: self::bucket();
        $key = ltrim($key ?: self::key(), '/');

        return 's3://'.$bucket.'/'.$key;
    }

    private static function read(string $name): string
    {
        $fromProcess = getenv($name);
        if ($fromProcess !== false && $fromProcess !== '') {
            return $fromProcess;
        }

        $fromEnv = $_ENV[$name] ?? '';

        return is_string($fromEnv) ? $fromEnv : '';
    }
}
