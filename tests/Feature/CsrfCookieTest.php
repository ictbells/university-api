<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

class CsrfCookieTest extends TestCase
{
    public function test_csrf_cookie_uses_app_specific_name(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie(VerifyCsrfToken::COOKIE);

        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('sanctum.csrf-cookie'),
            'sanctum.csrf-cookie must be registered in routes/web.php so optimize keeps it',
        );
    }
}
