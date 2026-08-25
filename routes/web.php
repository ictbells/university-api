<?php

use App\Http\Controllers\ApiDocumentationController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/', function () {
    return response()->json(['message' => 'API is running', 'status' => 'success', 'version' => '1.0.0', 'timestamp' => now()->toDateTimeString()]);
});

Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('sanctum.csrf-cookie');

Route::get('/api/docs', [ApiDocumentationController::class, 'ui'])->name('api.docs');
Route::get('/api/docs/openapi.json', [ApiDocumentationController::class, 'spec'])->name('api.docs.spec');

Route::get('/', function () {
    return response()->json(['message' => 'API is running', 'status' => 'success', 'version' => '1.0.0', 'timestamp' => now()->toDateTimeString()]);
});

Route::get('/api/docs', [ApiDocumentationController::class, 'ui'])->name('api.docs');
Route::get('/api/docs/openapi.json', [ApiDocumentationController::class, 'spec'])->name('api.docs.spec');
