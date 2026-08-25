<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;

class VerifyCsrfToken extends Middleware
{
    public const COOKIE = 'Bells-XSRF-TOKEN';

    protected function newCookie($request, $config)
    {
        return new Cookie(
            self::COOKIE,
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    public static function serialized()
    {
        return EncryptCookies::serialized(self::COOKIE);
    }
}
