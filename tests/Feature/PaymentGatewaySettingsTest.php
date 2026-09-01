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
            ->assertJsonPath('payment_gateways.wema.label', 'Wema Bank');
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
            ->assertJsonPath('message', 'Wema Bank is not configured. Add ALATPay keys in the server environment first.');

        $this->assertSame('paystack', PaymentGatewaySettings::active());
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
