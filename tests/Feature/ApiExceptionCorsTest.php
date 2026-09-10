<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiExceptionCorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cors.paths' => ['api/*', 'sanctum/csrf-cookie', 'api/sanctum/csrf-cookie'],
            'cors.allowed_methods' => ['*'],
            'cors.allowed_origins' => ['https://staff.example.com'],
            'cors.allowed_origins_patterns' => [],
            'cors.allowed_headers' => ['*'],
            'cors.exposed_headers' => ['X-Request-Id'],
            'cors.supports_credentials' => true,
        ]);
    }

    public function test_validation_error_on_login_includes_cors_headers(): void
    {
        $this->withHeader('Origin', 'https://staff.example.com')
            ->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertHeader('Access-Control-Allow-Origin', 'https://staff.example.com')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unhandled_api_exception_includes_cors_headers(): void
    {
        Route::middleware('api')->post('/api/__cors_exception_probe', function () {
            throw new RuntimeException('forced failure for cors probe');
        });

        $this->withHeader('Origin', 'https://staff.example.com')
            ->postJson('/api/__cors_exception_probe')
            ->assertStatus(500)
            ->assertHeader('Access-Control-Allow-Origin', 'https://staff.example.com')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
