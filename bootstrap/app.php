<?php

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
        $middleware->validateCsrfTokens(except: [
            'api/login',
            'api/register',
            'api/forgot-password',
            'api/reset-password',
            'api/two-factor/*',
            'api/payments/paystack/webhook',
        ]);
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'academic.resource' => \App\Http\Middleware\EnsureAcademicResource::class,
            'student' => \App\Http\Middleware\EnsureStudent::class,
            'staff.security' => \App\Http\Middleware\EnsureStaffSecurity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
