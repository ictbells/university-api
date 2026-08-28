<?php

namespace App\Support;

final class PortalUrl
{
    public static function student(string $path = ''): string
    {
        return self::join(self::studentBase(), $path);
    }

    public static function staff(string $path = ''): string
    {
        return self::join(
            self::absolute((string) config('app.frontend_url'), 'http://localhost:5173'),
            $path,
        );
    }

    public static function refereeInvite(string $plainToken): string
    {
        return self::student('referee/'.rawurlencode($plainToken));
    }

    public static function studentBase(): string
    {
        return self::absolute((string) config('app.student_url'), self::fallbackStudentBase());
    }

    private static function fallbackStudentBase(): string
    {
        return rtrim(self::origin((string) config('app.url')), '/').'/student';
    }

    private static function absolute(string $configured, string $fallbackAbsolute): string
    {
        $configured = trim($configured);
        if ($configured === '') {
            return rtrim($fallbackAbsolute, '/');
        }
        if (self::isAbsoluteHttp($configured)) {
            return rtrim($configured, '/');
        }

        return rtrim(self::origin((string) config('app.url')), '/').'/'.ltrim($configured, '/');
    }

    private static function isAbsoluteHttp(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && ! empty($parts['host']);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'http://localhost';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private static function join(string $base, string $path): string
    {
        $base = rtrim($base, '/');
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return $base;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
