<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StudentForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_portal_forgot_password_sends_reset_to_email(): void
    {
        Notification::fake();
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create(['email' => 'ada@example.com', 'status' => 'active']);
        $user->roles()->attach($role->id);

        $this->postJson('/api/forgot-password', [
            'portal' => 'student',
            'email' => 'ada@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If that email exists, a reset link was sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_student_portal_forgot_password_requires_email(): void
    {
        $this->postJson('/api/forgot-password', [
            'portal' => 'student',
            'login' => 'BUT/2026/0001',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
