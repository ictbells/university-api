<?php

namespace Tests\Feature;

use App\Mail\StaffCredentialsMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffCredentialsEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_staff_user_emails_login_details(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', [
            'name' => 'Ada Okoye',
            'email' => 'ada.okoye@bells.edu.ng',
            'password' => 'Secret1!x',
            'password_confirmation' => 'Secret1!x',
            'staff_title' => 'Admissions officer',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'ada.okoye@bells.edu.ng');

        $user = User::query()->where('email', 'ada.okoye@bells.edu.ng')->firstOrFail();
        $this->assertTrue(Hash::check('Secret1!x', $user->password));

        Mail::assertSent(StaffCredentialsMail::class, function (StaffCredentialsMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('ada.okoye@bells.edu.ng')
                && str_contains($html, 'Ada Okoye')
                && str_contains($html, 'ada.okoye@bells.edu.ng')
                && str_contains($html, 'Secret1!x')
                && str_contains($html, 'Open staff portal');
        });
    }

    public function test_disabled_staff_account_is_not_emailed_credentials(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', [
            'name' => 'Ada Okoye',
            'email' => 'ada.okoye@bells.edu.ng',
            'password' => 'Secret1!x',
            'password_confirmation' => 'Secret1!x',
            'status' => 'disabled',
        ])->assertCreated();

        Mail::assertNothingSent();
    }

    private function admin(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create([
            'email' => 'admin@bells.edu.ng',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }
}
