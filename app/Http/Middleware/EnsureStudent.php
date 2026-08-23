<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->student) {
            return response()->json(['message' => 'Student record is not available at this stage.'], 403);
        }

        return $next($request);
    }
}
