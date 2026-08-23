<?php

namespace App\Http\Middleware;

use App\Services\AcademicResourceAccess;
use App\Support\AcademicResourceCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAcademicResource
{
    public function __construct(private AcademicResourceAccess $access) {}

    public function handle(Request $request, Closure $next, string ...$resourceKeys): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'This action is not authorized.'], 403);
        }

        foreach ($resourceKeys as $key) {
            if (! AcademicResourceCatalog::isValidKey($key)) {
                return response()->json(['message' => 'Unknown academic resource.'], 500);
            }
        }

        if (! $this->access->canAccessAny($user, $resourceKeys)) {
            $reason = 'missing_both';
            foreach ($resourceKeys as $key) {
                $state = $this->access->accessState($user, $key);
                if ($state['reason'] !== 'missing_both') {
                    $reason = $state['reason'];
                    break;
                }
            }

            $message = match ($reason) {
                'missing_permission' => 'You do not have permission for this academic resource.',
                'missing_portal_link' => 'This academic resource is not enabled for your office portal link.',
                default => 'You do not have access to this academic resource.',
            };

            return response()->json([
                'message' => $message,
                'access_reason' => $reason,
            ], 403);
        }

        return $next($request);
    }
}
