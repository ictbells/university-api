<?php

namespace App\Support;

class DotenvWriter
{
    public static function set(string $key, string $value, ?string $path = null): bool
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            return false;
        }

        $path ??= base_path('.env');
        if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $line = $key.'='.self::encode($value);
        $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';
        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, $line, $contents, 1) ?? $contents;
        } else {
            $contents = rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
        }

        $ok = file_put_contents($path, $contents, LOCK_EX) !== false;
        if ($ok) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return $ok;
    }

    private static function encode(string $value): string
    {
        if ($value === '' || preg_match('/[\s#\'"\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
