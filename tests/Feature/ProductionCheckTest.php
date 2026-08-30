<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionCheckTest extends TestCase
{
    private function passingConfig(): array
    {
        return [
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://bells-api.example.com',
            'app.frontend_url' => 'https://staff.example.com',
            'app.student_url' => 'https://student.example.com',
            'app.super_admin_email' => 'superadmin@bellsuniversity.edu.ng',
            'app.super_admin_password' => 'unique-production-password',
            'session.secure' => true,
            'services.paystack.allow_demo_fulfill' => false,
            'services.paystack.secret' => 'sk_live_example',
            'services.paystack.public' => 'pk_live_example',
            'services.paystack.webhook_secret' => 'whsec_example',
            'services.prembly.allow_demo' => false,
            'services.prembly.key' => 'live_sk_example',
            'services.prembly.app_id' => 'live_pk_example',
            'sanctum.stateful' => ['staff.example.com', 'www.staff.example.com', 'bells-api.example.com'],
            'mail.default' => 'ses',
            'filesystems.default' => 's3',
            'logging.channels.single.level' => 'info',
        ];
    }

    public function test_skips_when_not_production(): void
    {
        $this->artisan('production:check')
            ->expectsOutputToContain('Skipping production checks')
            ->assertSuccessful();
    }

    public function test_passes_with_safe_production_config(): void
    {
        config($this->passingConfig());

        $this->artisan('production:check', ['--force' => true])
            ->expectsOutputToContain('Production check passed.')
            ->assertSuccessful();
    }

    public function test_fails_when_debug_is_on(): void
    {
        config($this->passingConfig());
        config(['app.debug' => true]);

        $this->artisan('production:check', ['--force' => true])
            ->expectsOutputToContain('APP_DEBUG must be false.')
            ->assertFailed();
    }

    public function test_fails_when_stateful_domains_include_localhost(): void
    {
        config($this->passingConfig());
        config(['sanctum.stateful' => ['localhost:5173', 'staff.example.com']]);

        $this->artisan('production:check', ['--force' => true])
            ->expectsOutputToContain('SANCTUM_STATEFUL_DOMAINS must not include localhost')
            ->assertFailed();
    }

    public function test_allows_paystack_test_keys_without_webhook_secret(): void
    {
        config($this->passingConfig());
        config([
            'services.paystack.secret' => 'sk_test_example',
            'services.paystack.public' => 'pk_test_example',
            'services.paystack.webhook_secret' => '',
        ]);

        $this->artisan('production:check', ['--force' => true])
            ->expectsOutputToContain('Production check passed.')
            ->doesntExpectOutputToContain('Use live keys')
            ->doesntExpectOutputToContain('webhook authenticity')
            ->assertSuccessful();
    }

    public function test_fails_when_demo_payments_are_enabled(): void
    {
        config($this->passingConfig());
        config(['services.paystack.allow_demo_fulfill' => true]);

        $this->artisan('production:check', ['--force' => true])
            ->expectsOutputToContain('PAYSTACK_ALLOW_DEMO_FULFILL must be false.')
            ->assertFailed();
    }

    public function test_fails_when_super_admin_env_is_missing(): void
    {
        config($this->passingConfig());
        config([
            'app.super_admin_email' => '',
            'app.super_admin_password' => '',
        ]);

        $this->artisan('production:check', ['--force' => true])
            ->expectsOutputToContain('SUPER_ADMIN_EMAIL must be a valid email.')
            ->expectsOutputToContain('SUPER_ADMIN_PASSWORD must be set.')
            ->assertFailed();
    }
}
