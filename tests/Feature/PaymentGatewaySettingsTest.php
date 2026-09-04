<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_settings_default_to_paystack(): void
    {
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->getJson('/api/security-settings')
            ->assertOk()
            ->assertJsonPath('payment_gateway', 'paystack')
            ->assertJsonPath('payment_gateways.paystack.label', 'Paystack')
            ->assertJsonPath('payment_gateways.wema.label', 'Wema Bank')
            ->assertJsonPath('payment_gateways.paygate.label', 'PayGate (Upperlink)');
    }

    public function test_staff_can_switch_to_wema_when_configured(): void
    {
        config([
            'services.wema.public' => 'pk_wema_test',
            'services.wema.secret' => 'sk_wema_test',
            'services.wema.business_id' => 'biz-wema-test',
        ]);
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'payment_gateway' => 'wema',
        ])
            ->assertOk()
            ->assertJsonPath('payment_gateway', 'wema')
            ->assertJsonPath('payment_gateways.wema.configured', true);

        $this->assertSame('wema', PaymentGatewaySettings::active());
    }

    public function test_staff_can_switch_to_paygate_when_configured(): void
    {
        config([
            'services.paygate.merchant_id' => 'BELLSMERCH',
            'services.paygate.username' => 'user',
            'services.paygate.password' => 'pass',
            'services.paygate.secret' => 'secret',
        ]);
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'payment_gateway' => 'paygate',
        ])
            ->assertOk()
            ->assertJsonPath('payment_gateway', 'paygate')
            ->assertJsonPath('payment_gateways.paygate.configured', true);

        $this->assertSame('paygate', PaymentGatewaySettings::active());
    }

    public function test_cannot_select_wema_when_keys_are_missing(): void
    {
        config([
            'services.wema.public' => '',
            'services.wema.secret' => '',
            'services.wema.business_id' => '',
        ]);
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'payment_gateway' => 'wema',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Wema Bank is not configured. Add WEMA_ALATPAY_PUBLIC_KEY, WEMA_ALATPAY_SECRET_KEY, WEMA_ALATPAY_BUSINESS_ID in the server environment first.');

        $this->assertSame('paystack', PaymentGatewaySettings::active());
    }

    public function test_cannot_select_paygate_when_keys_are_missing(): void
    {
        config([
            'services.paygate.merchant_id' => '',
            'services.paygate.username' => '',
            'services.paygate.password' => '',
            'services.paygate.secret' => '',
        ]);
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'payment_gateway' => 'paygate',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'PayGate is not configured. Add PAYGATE_MERCHANT_ID, PAYGATE_USERNAME, PAYGATE_PASSWORD, PAYGATE_SECRET_KEY in the server environment first.');

        $this->assertSame('paystack', PaymentGatewaySettings::active());
    }

    public function test_wema_stays_unconfigured_until_business_id_is_set(): void
    {
        config([
            'services.wema.public' => 'pk_wema_test',
            'services.wema.secret' => 'sk_wema_test',
            'services.wema.business_id' => '',
        ]);
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->getJson('/api/security-settings')
            ->assertOk()
            ->assertJsonPath('payment_gateways.wema.configured', false)
            ->assertJsonPath('payment_gateways.wema.missing', ['WEMA_ALATPAY_BUSINESS_ID']);

        $this->putJson('/api/security-settings', [
            'payment_gateway' => 'wema',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Wema Bank is not configured. Add WEMA_ALATPAY_BUSINESS_ID in the server environment first.');
    }

    public function test_updating_payment_gateway_requires_settings_manage(): void
    {
        Sanctum::actingAs($this->staffUser([]));

        $this->putJson('/api/security-settings', [
            'payment_gateway' => 'paystack',
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => 'admin', 'label' => $key],
            );
        }

        $role = Role::query()->create([
            'name' => 'Settings tester',
            'slug' => 'settings-tester-'.Str::lower(Str::random(8)),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
