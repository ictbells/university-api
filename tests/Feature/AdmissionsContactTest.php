<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdmissionsContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionsContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_info_is_public(): void
    {
        AdmissionsContactSettings::update([
            'admissions_email' => 'admissions@example.edu.ng',
            'admissions_phone' => '+234 801 234 5678',
        ]);

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJson([
                'admissions_email' => 'admissions@example.edu.ng',
                'admissions_phone' => '+234 801 234 5678',
            ]);
    }

    public function test_staff_can_update_admissions_contact_from_application_settings(): void
    {
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'admissions_email' => 'hello@bellsuniversity.edu.ng',
            'admissions_phone' => '+234 809 111 2222',
        ])
            ->assertOk()
            ->assertJsonPath('admissions_email', 'hello@bellsuniversity.edu.ng')
            ->assertJsonPath('admissions_phone', '+234 809 111 2222');

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('admissions_email', 'hello@bellsuniversity.edu.ng');
    }

    public function test_staff_can_update_staff_login_support_from_application_settings(): void
    {
        Sanctum::actingAs($this->staffUser(['settings.manage']));

        $this->putJson('/api/security-settings', [
            'staff_support_label' => 'Helpdesk',
            'staff_support_email' => 'helpdesk@bellsuniversity.edu.ng',
            'staff_support_phone' => '+234 809 000 1111',
        ])
            ->assertOk()
            ->assertJsonPath('staff_support_label', 'Helpdesk')
            ->assertJsonPath('staff_support_email', 'helpdesk@bellsuniversity.edu.ng')
            ->assertJsonPath('staff_support_phone', '+234 809 000 1111');

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('staff_support_label', 'Helpdesk')
            ->assertJsonPath('staff_support_email', 'helpdesk@bellsuniversity.edu.ng');
    }

    public function test_updating_admissions_contact_requires_settings_manage(): void
    {
        Sanctum::actingAs($this->staffUser([]));

        $this->putJson('/api/security-settings', [
            'admissions_email' => 'hello@bellsuniversity.edu.ng',
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
