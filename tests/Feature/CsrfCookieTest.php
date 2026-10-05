<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CsrfCookieTest extends TestCase
{
    public function test_csrf_cookie_is_available_on_web_and_api_paths(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie(VerifyCsrfToken::COOKIE);

        $this->get('/api/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie(VerifyCsrfToken::COOKIE);

        $this->assertTrue(Route::has('sanctum.csrf-cookie'));
        $this->assertTrue(Route::has('sanctum.csrf-cookie.api'));
    }

    public function test_bearer_token_requests_skip_csrf(): void
    {
        Route::post('/__csrf_bearer_probe', fn () => response()->json(['ok' => true]))
            ->middleware(VerifyCsrfToken::class);

        $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/__csrf_bearer_probe')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
