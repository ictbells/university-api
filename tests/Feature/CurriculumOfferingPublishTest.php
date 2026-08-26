<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\CourseRegistrationService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurriculumOfferingPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_creates_unlimited_section_a_for_mapped_courses(): void
    {
        [$user, $program, $mapped, $unmapped, $term, $level] = $this->seedCurriculum();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/offerings/from-curriculum', [
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
        ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('course_count', 1);

        $this->assertDatabaseHas('course_offerings', [
            'course_id' => $mapped->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
            'capacity' => null,
        ]);
        $this->assertDatabaseMissing('course_offerings', [
            'course_id' => $unmapped->id,
            'academic_term_id' => $term->id,
        ]);

        $student = $this->studentOn($program);
        $available = app(CourseRegistrationService::class)->availableOfferings($student, $term);
        $this->assertTrue($available->contains(fn (array $row) => (int) $row['course']['id'] === $mapped->id));
        $this->assertTrue($available->first()['unlimited']);
    }

    public function test_publish_skips_courses_that_already_have_an_offering(): void
    {
        [$user, $program, $mapped, , $term] = $this->seedCurriculum();
        CourseOffering::query()->create([
            'course_id' => $mapped->id,
            'academic_term_id' => $term->id,
            'section' => 'B',
            'capacity' => 40,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/offerings/from-curriculum', [
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
        ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(1, CourseOffering::query()->where('course_id', $mapped->id)->count());
    }

    public function test_publish_all_programmes_creates_one_offering_per_course(): void
    {
        [$user, $programA, $mapped, , $term, $level, $programB] = $this->seedCurriculum();
        $programB->courses()->sync([
            $mapped->id => ['academic_level_id' => $level->id, 'bucket' => 'departmental'],
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/offerings/from-curriculum', [
            'academic_term_id' => $term->id,
        ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('course_count', 1);

        $this->assertSame(1, CourseOffering::query()->where('course_id', $mapped->id)->where('academic_term_id', $term->id)->count());
    }

    public function test_publish_rejects_programme_with_no_curriculum(): void
    {
        [$user, , , , $term, , $empty] = $this->seedCurriculum();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/offerings/from-curriculum', [
            'academic_term_id' => $term->id,
            'program_id' => $empty->id,
        ])
            ->assertUnprocessable()
            ->assertJsonFragment(['This programme has no courses assigned yet. Map them on Programme courses first.']);
    }

    /**
     * @return array{0: User, 1: Program, 2: Course, 3: Course, 4: AcademicTerm, 5: AcademicLevel, 6: Program}
     */
    private function seedCurriculum(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Physics']);
        $programA = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Physics',
            'code' => 'BSC-PHY',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $programB = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Chemistry',
            'code' => 'BSC-CHE',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $mapped = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'PHY101',
            'title' => 'General Physics I',
            'units' => 3,
            'course_type' => 'departmental',
        ]);
        $unmapped = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'PHY999',
            'title' => 'Not on curriculum',
            'units' => 2,
            'course_type' => 'departmental',
        ]);
        $level = AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $programA->courses()->sync([
            $mapped->id => ['academic_level_id' => $level->id, 'bucket' => 'departmental'],
        ]);
        $session = AcademicSession::query()->create([
            'label' => '2026/2027',
            'starts_on' => '2026-10-01',
            'ends_on' => '2027-09-30',
        ]);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);

        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-publish', 'is_active' => true]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'academic.offerings.manage')->pluck('id'),
        );
        $office = OfficeDepartment::query()->create(['name' => 'Registry', 'code' => 'REG-OFF', 'is_active' => true]);
        $office->syncNavKeys(['offerings']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-PUB',
            'office_department_id' => $office->id,
        ]);

        return [$user->fresh(['roles.permissions', 'staff']), $programA, $mapped, $unmapped, $term, $level, $programB];
    }

    private function studentOn(Program $program): Student
    {
        $user = User::factory()->create(['status' => 'active']);

        return Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ])->load(['program.courses', 'application']);
    }
}
