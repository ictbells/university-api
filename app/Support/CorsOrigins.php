<?php

namespace App\Support;

class CorsOrigins
{
    /**
     * @param  list<string|null>  $urls
     * @return list<string>
     */
    public static function fromUrls(array $urls): array
    {
        $origins = [];
        foreach ($urls as $url) {
            foreach (self::originsFor((string) $url) as $origin) {
                if (! in_array($origin, $origins, true)) {
                    $origins[] = $origin;
                }
            }
        }

        return $origins;
    }

    /**
     * @return list<string>
     */
    public static function originsFor(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            $trimmed = rtrim($url, '/');

            return $trimmed !== '' ? [$trimmed] : [];
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $origins = [$origin];

        $host = $parts['host'];
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP)) {
            return $origins;
        }

        $altHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        $origins[] = $parts['scheme'].'://'.$altHost.(isset($parts['port']) ? ':'.$parts['port'] : '');

        return array_values(array_unique($origins));
    }

    /**
     * @return list<string>
     */
    public static function localPatterns(): array
    {
        if (! in_array(env('APP_ENV'), ['local', 'testing'], true)) {
            return [];
        }

        return [
            '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
            '#^https?://(10|192\.168|172\.(1[6-9]|2\d|3[0-1]))\.\d+\.\d+\.\d+(:\d+)?$#',
        ];
    }
}
