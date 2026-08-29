<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthAndSecurityHeadersTest extends TestCase
{
    public function test_health_endpoint_is_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Request-Id');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }
}
