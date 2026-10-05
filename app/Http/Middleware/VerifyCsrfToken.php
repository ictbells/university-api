<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class VerifyCsrfToken extends Middleware
{
    public const COOKIE = 'Bells-XSRF-TOKEN';

    public function handle($request, Closure $next)
    {
        // Staff/student SPAs authenticate with Bearer tokens and do not send
        // cookies (withCredentials: false). Skip CSRF for those requests so a
        // bloated shared-domain cookie jar cannot block the portal.
        if ($request instanceof Request && is_string($request->bearerToken()) && $request->bearerToken() !== '') {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

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
