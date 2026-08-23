<?php

use App\Http\Controllers\ApiDocumentationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/docs', [ApiDocumentationController::class, 'ui'])->name('api.docs');
Route::get('/api/docs/openapi.json', [ApiDocumentationController::class, 'spec'])->name('api.docs.spec');
