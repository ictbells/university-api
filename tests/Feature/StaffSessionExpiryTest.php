<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\StaffSecurityService;
use App\Support\PermissionCatalog;
use App\Support\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class StaffSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_me_includes_eight_hour_session_expiry(): void
    {
        $user = $this->staffUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk();

        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('security.session_max_hours', StaffSecurityService::SESSION_MAX_HOURS)
            ->assertJsonStructure(['security' => ['session_expires_at']]);

        $expiresAt = $this->withToken($token)->getJson('/api/me')->json('security.session_expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertTrue(now()->lt($expiresAt));
        $this->assertTrue(now()->addHours(StaffSecurityService::SESSION_MAX_HOURS + 1)->gt($expiresAt));
    }

    public function test_staff_session_ends_after_eight_hours(): void
    {
        $user = $this->staffUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk();

        $plainToken = $login->json('token');
        $accessToken = PersonalAccessToken::findToken($plainToken);
        $this->assertNotNull($accessToken);

        $accessToken->forceFill([
            'created_at' => now()->subHours(StaffSecurityService::SESSION_MAX_HOURS)->subMinute(),
        ])->save();

        $this->withToken($plainToken)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired');

        $this->assertNull(PersonalAccessToken::findToken($plainToken));
    }

    public function test_staff_session_still_valid_before_eight_hours(): void
    {
        $user = $this->staffUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk();

        $plainToken = $login->json('token');
        $accessToken = PersonalAccessToken::findToken($plainToken);
        $this->assertNotNull($accessToken);

        $accessToken->forceFill([
            'created_at' => now()->subHours(StaffSecurityService::SESSION_MAX_HOURS - 1),
        ])->save();

        $this->withToken($plainToken)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_staff_inactivity_timeout_ends_session(): void
    {
        Setting::setValue(SecuritySettings::INACTIVITY_LOGOUT_MINUTES, '15');

        $user = $this->staffUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk()
            ->assertJsonPath('security.inactivity_logout_minutes', 15);

        $plainToken = $login->json('token');

        $user->forceFill(['last_activity_at' => now()->subMinutes(16)])->save();

        $this->withToken($plainToken)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_timeout');

        $this->assertNull(PersonalAccessToken::findToken($plainToken));
    }

    public function test_staff_activity_within_timeout_keeps_session(): void
    {
        Setting::setValue(SecuritySettings::INACTIVITY_LOGOUT_MINUTES, '15');
        $user = $this->staffUser();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk();

        $plainToken = $login->json('token');
        $user->forceFill(['last_activity_at' => now()->subMinutes(10)])->save();

        $this->withToken($plainToken)
            ->getJson('/api/me')
            ->assertOk();

        $this->assertTrue($user->fresh()->last_activity_at->greaterThan(now()->subMinute()));
    }

    private function staffUser(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Staff',
            'slug' => 'staff-session-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => 'Session Staff',
            'email' => 'session.staff@bells.edu.ng',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }
}
