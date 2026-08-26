<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\UnitLimit;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitLimitScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_schedule_saves_first_and_second_semester_buckets(): void
    {
        [$user, $program, $level, $first, $second] = $this->seedLimits();
        Sanctum::actingAs($user);

        $this->putJson('/api/academic/unit-limits/sync', [
            'program_id' => $program->id,
            'academic_level_id' => $level->id,
            'academic_term_ids' => [$first->id, $second->id],
            'limits' => [
                ['academic_term_id' => $first->id, 'bucket' => 'departmental', 'min_units' => 15, 'max_units' => 15],
                ['academic_term_id' => $second->id, 'bucket' => 'departmental', 'min_units' => 13, 'max_units' => 13],
                ['academic_term_id' => $first->id, 'bucket' => 'general', 'min_units' => 2, 'max_units' => 2],
                ['academic_term_id' => $second->id, 'bucket' => 'general', 'min_units' => 2, 'max_units' => 2],
            ],
        ])->assertOk()->assertJsonCount(4);

        $this->assertSame(4, UnitLimit::query()->count());
        $this->assertDatabaseHas('unit_limits', [
            'program_id' => $program->id,
            'academic_level_id' => $level->id,
            'academic_term_id' => $first->id,
            'bucket' => 'departmental',
            'min_units' => 15,
            'max_units' => 15,
        ]);
        $this->assertDatabaseHas('unit_limits', [
            'program_id' => $program->id,
            'academic_term_id' => $second->id,
            'bucket' => 'departmental',
            'min_units' => 13,
        ]);
    }

    public function test_blank_cell_removes_that_bucket_from_the_schedule(): void
    {
        [$user, $program, $level, $first, $second] = $this->seedLimits();
        UnitLimit::query()->create([
            'program_id' => $program->id,
            'academic_level_id' => $level->id,
            'academic_term_id' => $first->id,
            'bucket' => 'faculty',
            'min_units' => 3,
            'max_units' => 3,
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/academic/unit-limits/sync', [
            'program_id' => $program->id,
            'academic_level_id' => $level->id,
            'academic_term_ids' => [$first->id, $second->id],
            'limits' => [
                ['academic_term_id' => $first->id, 'bucket' => 'departmental', 'min_units' => 15, 'max_units' => 15],
                ['academic_term_id' => $first->id, 'bucket' => 'faculty', 'min_units' => null, 'max_units' => null],
            ],
        ])->assertOk();

        $this->assertSoftDeleted('unit_limits', [
            'program_id' => $program->id,
            'bucket' => 'faculty',
        ]);
        $this->assertDatabaseHas('unit_limits', [
            'bucket' => 'departmental',
            'min_units' => 15,
        ]);
    }

    public function test_destroy_group_removes_the_programme_level_session_schedule(): void
    {
        [$user, $program, $level, $first, $second] = $this->seedLimits();
        foreach ([$first, $second] as $term) {
            UnitLimit::query()->create([
                'program_id' => $program->id,
                'academic_level_id' => $level->id,
                'academic_term_id' => $term->id,
                'bucket' => 'departmental',
                'min_units' => 12,
                'max_units' => 12,
            ]);
        }
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/unit-limits/destroy-group', [
            'program_id' => $program->id,
            'academic_level_id' => $level->id,
            'academic_term_ids' => [$first->id, $second->id],
        ])->assertNoContent();

        $this->assertSame(0, UnitLimit::query()->count());
    }

    /**
     * @return array{0: User, 1: Program, 2: AcademicLevel, 3: AcademicTerm, 4: AcademicTerm}
     */
    private function seedLimits(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $level = AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $session = AcademicSession::query()->create([
            'label' => '2026/2027',
            'starts_on' => '2026-10-01',
            'ends_on' => '2027-09-30',
        ]);
        $first = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        $second = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Second',
            'session_label' => '2026/2027',
            'is_current' => false,
        ]);
        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-limits', 'is_active' => true]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'academic.enrollments.manage')->pluck('id'),
        );
        $office = OfficeDepartment::query()->create(['name' => 'Registry', 'code' => 'REG-LIM', 'is_active' => true]);
        $office->syncNavKeys(['unit-limits']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-LIM',
            'office_department_id' => $office->id,
        ]);

        return [$user->fresh(['roles.permissions', 'staff']), $program, $level, $first, $second];
    }
}
