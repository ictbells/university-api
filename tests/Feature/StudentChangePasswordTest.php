<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_change_password_with_current_password(): void
    {
        $user = $this->applicant('ada@example.com');
        Sanctum::actingAs($user);

        $this->patchJson('/api/me', [
            'current_password' => 'password',
            'password' => 'Secret1!x',
            'password_confirmation' => 'Secret1!x',
        ])->assertOk();

        $this->assertTrue(Hash::check('Secret1!x', $user->fresh()->password));
        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_student_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->applicant('ada@example.com');
        Sanctum::actingAs($user);

        $this->patchJson('/api/me', [
            'current_password' => 'wrong-password',
            'password' => 'Secret1!x',
            'password_confirmation' => 'Secret1!x',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    private function applicant(string $email): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create([
            'email' => $email,
            'status' => 'active',
            'password' => 'password',
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }
}
