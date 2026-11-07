<?php

namespace Tests\Feature;

use App\Mail\StaffLoginNotificationMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginDecryptResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_login_succeeds_when_two_factor_secret_mac_is_invalid(): void
    {
        Mail::fake();
        $user = $this->staffUser();
        // Simulate ciphertext from a previous APP_KEY (bypass cast so MAC stays invalid).
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => 'eyJpdiI6ImJhZCIsInZhbHVlIjoiYmFkIiwibWFjIjoiYmFkIiwidGFnIjoiIn0=',
            'two_factor_confirmed_at' => now(),
        ]);
        $user->refresh();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Secret1!x',
            'portal' => 'staff',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('token', fn ($token) => is_string($token) && $token !== '');

        Mail::assertSent(StaffLoginNotificationMail::class);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_staff_login_succeeds_when_related_student_nin_mac_is_invalid(): void
    {
        Mail::fake();
        $user = $this->staffUser();
        \App\Models\Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'status' => 'active',
            'current_level' => 100,
            'nin' => null,
        ]);
        // Bypass cast to store unreadable ciphertext.
        \Illuminate\Support\Facades\DB::table('students')
            ->where('user_id', $user->id)
            ->update(['nin' => 'eyJpdiI6ImJhZCIsInZhbHVlIjoiYmFkIiwibWFjIjoiYmFkIiwidGFnIjoiIn0=']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Secret1!x',
            'portal' => 'staff',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id);

        Mail::assertSent(StaffLoginNotificationMail::class);
    }

    public function test_resilient_encrypted_round_trips_valid_secrets(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $fresh = $user->fresh();
        $this->assertSame('JBSWY3DPEHPK3PXP', $fresh->two_factor_secret);
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $fresh->getAttributes()['two_factor_secret']);
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypt::decryptString($fresh->getAttributes()['two_factor_secret']));
    }

    private function staffUser(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'dashboard.view'],
            ['label' => 'Dashboard', 'module' => 'dashboard'],
        );
        $role = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'is_system' => true, 'is_active' => true],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create([
            'email' => 'admin@bells.test',
            'password' => 'Secret1!x',
            'status' => 'active',
        ]);
        $user->roles()->sync([$role->id]);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'STF-1',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => 'active',
        ]);

        return $user;
    }
}
