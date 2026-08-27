<?php

namespace Tests\Feature;

use App\Mail\StaffLoginNotificationMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffLoginNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_staff_login_sends_notification_email(): void
    {
        Mail::fake();
        $user = $this->staffUser('ada.staff@bells.edu.ng', 'Ada Staff');

        $this->withServerVariables(['REMOTE_ADDR' => '102.89.23.10'])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
                'portal' => 'staff',
            ], ['User-Agent' => 'BellsStaffTest/1.0'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        Mail::assertSent(StaffLoginNotificationMail::class, function (StaffLoginNotificationMail $mail) use ($user) {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && str_contains($html, 'Ada Staff')
                && str_contains($html, '102.89.23.10')
                && str_contains($html, 'BellsStaffTest/1.0')
                && str_contains($html, 'Reset password');
        });
    }

    public function test_failed_staff_login_does_not_send_notification_email(): void
    {
        Mail::fake();
        $user = $this->staffUser('ada.staff@bells.edu.ng', 'Ada Staff');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'portal' => 'staff',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_student_login_does_not_send_staff_notification_email(): void
    {
        Mail::fake();
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create([
            'email' => 'ada.applicant@example.com',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);

        $this->postJson('/api/login', [
            'login' => 'APP/2026/00001',
            'password' => 'password',
            'portal' => 'student',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    private function staffUser(string $email, string $name): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Staff',
            'slug' => 'staff-login-mail-'.uniqid(),
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
