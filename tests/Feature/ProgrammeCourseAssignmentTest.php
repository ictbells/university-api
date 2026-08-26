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

class ProgrammeCourseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_assign_courses_to_a_programme(): void
    {
        [$user, $program, $course, $level] = $this->seedCatalog();
        Sanctum::actingAs($user);

        $this->putJson("/api/academic/programs/{$program->id}/courses", [
            'courses' => [
                ['course_id' => $course->id, 'academic_level_id' => $level->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('courses.0.id', $course->id)
            ->assertJsonPath('courses.0.pivot.academic_level_id', $level->id);

        $this->assertDatabaseHas('program_course', [
            'program_id' => $program->id,
            'course_id' => $course->id,
            'academic_level_id' => $level->id,
        ]);
    }

    public function test_students_only_see_courses_assigned_to_their_programme(): void
    {
        [, $programA, $mapped, , $term, $programB] = $this->seedCatalog();
        $unmapped = Course::query()->create([
            'department_id' => $programA->department_id,
            'code' => 'CSC999',
            'title' => 'Not on curriculum',
            'units' => 2,
            'course_type' => 'departmental',
        ]);
        $mappedOffering = CourseOffering::query()->create([
            'course_id' => $mapped->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $unmappedOffering = CourseOffering::query()->create([
            'course_id' => $unmapped->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $programA->courses()->sync([$mapped->id]);

        $studentA = $this->studentOn($programA);
        $studentB = $this->studentOn($programB);
        $service = app(CourseRegistrationService::class);

        $idsA = $service->availableOfferings($studentA, $term)->pluck('id')->all();
        $idsB = $service->availableOfferings($studentB, $term)->pluck('id')->all();

        $this->assertContains($mappedOffering->id, $idsA);
        $this->assertNotContains($unmappedOffering->id, $idsA);
        $this->assertNotContains($mappedOffering->id, $idsB);
    }

    /**
     * @return array{0: User, 1: Program, 2: Course, 3: AcademicLevel, 4: AcademicTerm, 5: Program}
     */
    private function seedCatalog(): array
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $programA = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $programB = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Cybersecurity',
            'code' => 'BSC-CYB',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'CSC101',
            'title' => 'Intro to Computing',
            'units' => 3,
            'course_type' => 'departmental',
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
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);

        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-curriculum', 'is_active' => true]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['academic.programmes.manage', 'academic.courses.manage'])->pluck('id'),
        );
        $office = OfficeDepartment::query()->create(['name' => 'Registry', 'code' => 'REG', 'is_active' => true]);
        $office->syncNavKeys(['programmes', 'courses', 'programme-courses']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-CUR',
            'office_department_id' => $office->id,
        ]);

        return [$user->fresh(['roles.permissions', 'staff']), $programA, $course, $level, $term, $programB];
    }

    private function studentOn(Program $program): Student
    {
        $user = User::factory()->create(['status' => 'active']);

        return Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ])->load('program.department');
    }
}
