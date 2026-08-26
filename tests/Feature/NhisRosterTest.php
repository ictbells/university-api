<?php

namespace Tests\Feature;

use App\Models\MedicalProfile;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NhisRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_staff_can_list_and_enrol_students_on_nhis(): void
    {
        $staff = $this->staffUser(['medical.view_any', 'medical.manage'], ['medical']);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'matric_number' => 'BUT/2026/R/0099',
            'status' => 'active',
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/medical/nhis?status=not_enrolled&search=Ada')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.effective_coverage_percent', 0);

        $this->putJson('/api/medical/'.$student->id, [
            'nhis_enrolled' => true,
            'nhis_number' => 'NHIS-9988',
            'nhis_provider' => 'NHIS Abuja',
            'nhis_coverage_percent' => 90,
            'nhis_valid_until' => now()->addYear()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('nhis_enrolled', true)
            ->assertJsonPath('nhis_number', 'NHIS-9988');

        $this->assertTrue(
            MedicalProfile::query()->where('student_id', $student->id)->where('nhis_enrolled', true)->exists()
        );

        $this->getJson('/api/medical/nhis?status=enrolled&search=NHIS-9988')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.matric_number', 'BUT/2026/R/0099')
            ->assertJsonPath('data.0.medical_profile.nhis_provider', 'NHIS Abuja')
            ->assertJsonPath('summary.enrolled', 1);
    }

    public function test_staff_enrol_by_matric_and_can_set_fixed_cover_amount(): void
    {
        $staff = $this->staffUser(['medical.view_any', 'medical.manage'], ['medical']);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Chidi',
            'last_name' => 'Eze',
            'matric_number' => 'BUT/2026/R/0100',
            'status' => 'active',
        ]);

        Sanctum::actingAs($staff);

        $this->putJson('/api/medical/nhis', [
            'matric_number' => 'but/2026/r/0100',
            'nhis_enrolled' => true,
            'nhis_number' => 'NHIS-0100',
            'nhis_coverage_amount' => 1500,
        ])->assertOk()
            ->assertJsonPath('nhis_enrolled', true)
            ->assertJsonPath('nhis_coverage_amount', '1500.00')
            ->assertJsonPath('nhis_coverage_percent', null);

        $this->assertTrue(
            MedicalProfile::query()
                ->where('student_id', $student->id)
                ->where('nhis_enrolled', true)
                ->where('nhis_coverage_amount', 1500)
                ->exists()
        );

        $this->putJson('/api/medical/nhis', [
            'matric_number' => 'MISSING-MATRIC',
            'nhis_enrolled' => true,
        ])->assertStatus(422);

        $this->putJson('/api/medical/'.$student->id, [
            'nhis_enrolled' => true,
            'nhis_coverage_percent' => 80,
            'nhis_coverage_amount' => 2000,
        ])->assertStatus(422)->assertJsonValidationErrors(['nhis_coverage_amount']);
    }

    public function test_nhis_roster_requires_medical_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);
        $this->getJson('/api/medical/nhis')->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $navKeys
     */
    private function staffUser(array $permissions, array $navKeys): User
    {
        $role = Role::query()->create([
            'name' => 'Clinic staff',
            'slug' => 'clinic-'.substr(sha1(uniqid()), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);
        $office = OfficeDepartment::query()->create([
            'name' => 'Clinic '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys($navKeys);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
