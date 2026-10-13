<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_without_staff_row_can_sign_in_on_staff_portal(): void
    {
        $role = Role::query()->create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'is_system' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'favour.super@bells.edu.ng',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);
        $this->assertNull($user->fresh()->staff);
        $this->assertTrue($user->fresh()->isStaffPortalUser());

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_inactive_super_admin_role_still_allows_staff_portal(): void
    {
        $role = Role::query()->create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'is_system' => true,
            'is_active' => false,
        ]);
        $user = User::factory()->create([
            'email' => 'inactive.super@bells.edu.ng',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->fresh()->isStaffPortalUser());

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])->assertOk();
    }

    public function test_applicant_only_account_is_directed_to_student_portal(): void
    {
        $role = Role::query()->create([
            'name' => 'Applicant',
            'slug' => 'applicant',
            'is_system' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'only.applicant@example.com',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertStringContainsString('student portal', strtolower((string) data_get(
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
                'portal' => 'staff',
            ])->json(),
            'errors.email.0'
        )));
    }
}
