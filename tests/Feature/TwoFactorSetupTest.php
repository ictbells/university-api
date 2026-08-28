<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_setup_returns_a_scannable_qr_code(): void
    {
        Setting::setValue(SecuritySettings::TWO_FACTOR, '1');
        $user = $this->staffUser('ada.2fa@bells.edu.ng', 'Ada TwoFactor');

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('two_factor_setup_required', true);

        $setup = $this->postJson('/api/two-factor/setup', [
            'challenge_id' => $login->json('challenge_id'),
        ])->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url', 'qr_code']);

        $secret = $setup->json('secret');
        $otpauth = $setup->json('otpauth_url');
        $qr = $setup->json('qr_code');

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertStringStartsWith('otpauth://totp/', $otpauth);
        $this->assertStringContainsString($secret, $otpauth);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr);
        $this->assertStringContainsString('<svg', (string) base64_decode((string) substr($qr, strlen('data:image/svg+xml;base64,')), true));
    }

    private function staffUser(string $email, string $name): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Staff',
            'slug' => 'staff-2fa-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }
}
