<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAcademicResource;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePortalNav;
use App\Http\Middleware\EnsureStaffSecurity;
use App\Http\Middleware\EnsureStudent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class => \App\Http\Middleware\VerifyCsrfToken::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/login',
            'api/register',
            'api/nin/preview',
            'api/forgot-password',
            'api/reset-password',
            'api/two-factor/*',
            'api/payments/paystack/webhook',
        ]);
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'academic.resource' => EnsureAcademicResource::class,
            'portal.nav' => EnsurePortalNav::class,
            'student' => EnsureStudent::class,
            'staff.security' => EnsureStaffSecurity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
