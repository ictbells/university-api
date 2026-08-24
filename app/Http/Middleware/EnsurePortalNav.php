<?php

namespace App\Http\Middleware;

use App\Services\StaffNavResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalNav
{
    public function __construct(private StaffNavResolver $nav) {}

    public function handle(Request $request, Closure $next, string $navKey): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'This action is not authorized.'], 403);
        }

        if ($this->nav->isUnrestricted($user)) {
            return $next($request);
        }

        $keys = $this->nav->resolve($user)['keys'] ?? [];
        if (! in_array($navKey, $keys, true)) {
            return response()->json([
                'message' => 'This module is not enabled for your office portal link.',
                'access_reason' => 'missing_portal_link',
            ], 403);
        }

        return $next($request);
    }
}
