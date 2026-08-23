<?php

namespace App\Http\Middleware;

use App\Services\StaffSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffSecurity
{
    public function __construct(private StaffSecurityService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->security->appliesTo($user)) {
            return $next($request);
        }

        if ($this->security->inactivityExceeded($user)) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your session expired due to inactivity.',
                'code' => 'session_timeout',
            ], 401);
        }

        if ($this->security->passwordChangeRequired($user) && ! $this->allowsPasswordChange($request)) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $this->security->touchActivity($user);
        }

        return $response;
    }

    private function allowsPasswordChange(Request $request): bool
    {
        if ($request->is('api/me') && in_array($request->method(), ['GET', 'PATCH'], true)) {
            return true;
        }
        if ($request->is('api/logout') && $request->isMethod('POST')) {
            return true;
        }

        return false;
    }
}
