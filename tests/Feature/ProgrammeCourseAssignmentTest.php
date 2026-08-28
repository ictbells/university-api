<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Grade;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\CourseRegistrationService;
use App\Support\GradeStatus;
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

    public function test_student_can_see_unpassed_lower_level_programme_courses(): void
    {
        [, $program, $course100, $level100, $term] = $this->seedCatalog();
        $level200 = AcademicLevel::query()->create([
            'name' => '200 Level',
            'code' => '200',
            'study_level' => 'undergraduate',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $level300 = AcademicLevel::query()->create([
            'name' => '300 Level',
            'code' => '300',
            'study_level' => 'undergraduate',
            'sort_order' => 3,
            'is_active' => true,
        ]);
        $course200 = Course::query()->create([
            'department_id' => $program->department_id,
            'code' => 'CSC201',
            'title' => 'Data Structures',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $course300 = Course::query()->create([
            'department_id' => $program->department_id,
            'code' => 'CSC301',
            'title' => 'Algorithms',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $program->courses()->sync([
            $course100->id => ['academic_level_id' => $level100->id, 'bucket' => 'departmental'],
            $course200->id => ['academic_level_id' => $level200->id, 'bucket' => 'departmental'],
            $course300->id => ['academic_level_id' => $level300->id, 'bucket' => 'departmental'],
        ]);
        $off100 = CourseOffering::query()->create([
            'course_id' => $course100->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $off200 = CourseOffering::query()->create([
            'course_id' => $course200->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $off300 = CourseOffering::query()->create([
            'course_id' => $course300->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $student = $this->studentOn($program);
        $student->update(['current_level' => 200]);
        $student = $student->fresh(['program']);
        $service = app(CourseRegistrationService::class);

        $available = $service->availableOfferings($student, $term);
        $ids = $available->pluck('id')->all();

        $this->assertContains($off100->id, $ids);
        $this->assertContains($off200->id, $ids);
        $this->assertNotContains($off300->id, $ids);
        $this->assertTrue((bool) $available->firstWhere('id', $off100->id)['is_outstanding']);
        $this->assertFalse((bool) $available->firstWhere('id', $off200->id)['is_outstanding']);
    }

    public function test_passed_lower_level_courses_are_not_offered_again(): void
    {
        [, $program, $course100, $level100, $term] = $this->seedCatalog();
        $level200 = AcademicLevel::query()->create([
            'name' => '200 Level',
            'code' => '200',
            'study_level' => 'undergraduate',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $program->courses()->sync([
            $course100->id => ['academic_level_id' => $level100->id, 'bucket' => 'departmental'],
        ]);
        $previous = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
            'closed_at' => now()->subDay(),
        ]);
        $previousTerm = AcademicTerm::query()->create([
            'academic_session_id' => $previous->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => false,
        ]);
        $oldOffering = CourseOffering::query()->create([
            'course_id' => $course100->id,
            'academic_term_id' => $previousTerm->id,
            'section' => 'A',
        ]);
        $currentOffering = CourseOffering::query()->create([
            'course_id' => $course100->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $student = $this->studentOn($program);
        $student->update(['current_level' => 200]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'course_offering_id' => $oldOffering->id,
            'status' => 'enrolled',
            'registered_at' => now()->subYear(),
        ]);
        Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'sitting' => 'main',
            'letter' => 'B',
            'points' => 4,
            'score' => 62,
            'status' => GradeStatus::RELEASED,
        ]);
        $student = $student->fresh(['program']);
        $ids = app(CourseRegistrationService::class)->availableOfferings($student, $term)->pluck('id')->all();

        $this->assertNotContains($currentOffering->id, $ids);
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

    public function test_creating_a_course_requires_catalogue_type_and_programmes_are_optional(): void
    {
        [$user, $program] = $this->seedCatalog();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/courses', [
            'department_id' => $program->department_id,
            'code' => 'CSC201',
            'title' => 'Data Structures',
            'units' => 3,
            'status' => 'core',
        ])->assertUnprocessable();

        $this->postJson('/api/academic/courses', [
            'department_id' => $program->department_id,
            'code' => 'CSC201',
            'title' => 'Data Structures',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'CSC201')
            ->assertJsonPath('programs', []);

        $this->assertSame(0, Course::query()->where('code', 'CSC201')->first()?->programs()->count());
    }

    public function test_course_catalog_and_programme_courses_share_the_same_assignments(): void
    {
        [$user, $program, $course, $level, , $programB] = $this->seedCatalog();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/courses', [
            'department_id' => $program->department_id,
            'code' => 'CSC210',
            'title' => 'Algorithms',
            'units' => 3,
            'course_type' => 'faculty',
            'status' => 'core',
            'program_ids' => [$program->id, $programB->id],
        ])->assertCreated();

        $created = Course::query()->where('code', 'CSC210')->first();
        $this->assertNotNull($created);
        $this->assertDatabaseHas('program_course', [
            'program_id' => $program->id,
            'course_id' => $created->id,
            'bucket' => 'faculty',
        ]);
        $this->assertDatabaseHas('program_course', [
            'program_id' => $programB->id,
            'course_id' => $created->id,
            'bucket' => 'faculty',
        ]);

        $this->putJson("/api/academic/programs/{$program->id}/courses", [
            'courses' => [
                ['course_id' => $course->id, 'academic_level_id' => $level->id],
                ['course_id' => $created->id, 'academic_level_id' => $level->id],
            ],
        ])->assertOk();

        $this->assertTrue($created->fresh()->programs()->where('programs.id', $programB->id)->exists());
        $this->assertDatabaseHas('program_course', [
            'program_id' => $program->id,
            'course_id' => $created->id,
            'academic_level_id' => $level->id,
        ]);

        $catalog = collect($this->getJson('/api/academic/courses')->json());
        $listed = $catalog->firstWhere('id', $created->id);
        $this->assertNotNull($listed);
        $this->assertEqualsCanonicalizing(
            [$program->id, $programB->id],
            collect($listed['programs'] ?? [])->pluck('id')->all(),
        );

        $this->patchJson("/api/academic/courses/{$created->id}", [
            'title' => 'Algorithms II',
            'program_ids' => [$program->id],
        ])->assertOk();

        $this->assertFalse($created->fresh()->programs()->where('programs.id', $programB->id)->exists());
        $this->assertDatabaseHas('program_course', [
            'program_id' => $program->id,
            'course_id' => $created->id,
            'academic_level_id' => $level->id,
            'bucket' => 'faculty',
        ]);
    }

    public function test_jupeb_programme_cannot_mix_undergraduate_entry_modes(): void
    {
        [$user, $program] = $this->seedCatalog();
        Sanctum::actingAs($user);

        $this->postJson('/api/programs', [
            'department_id' => $program->department_id,
            'name' => 'Architecture JUPEB',
            'code' => 'ARC-JUPEB',
            'award_type' => 'JUPEB',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme', 'jupeb'],
            'duration_years' => 1,
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonFragment(['entry_modes' => ['JUPEB cannot share a programme with undergraduate or postgraduate. Create a separate JUPEB programme with its own levels and courses.']]);
    }

    public function test_jupeb_curriculum_rejects_undergraduate_levels(): void
    {
        [$user, $program, $course, $ugLevel] = $this->seedCatalog();
        $jupeb = Program::query()->create([
            'department_id' => $program->department_id,
            'name' => 'Architecture JUPEB',
            'code' => 'ARC-JPB',
            'award_type' => 'JUPEB',
            'study_level' => 'jupeb',
            'entry_modes' => ['jupeb'],
            'duration_years' => 1,
            'is_active' => true,
        ]);
        $jupebLevel = AcademicLevel::query()->create([
            'name' => 'JUPEB Year 1',
            'code' => '100',
            'study_level' => 'jupeb',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $this->putJson("/api/academic/programs/{$jupeb->id}/courses", [
            'courses' => [
                ['course_id' => $course->id, 'academic_level_id' => $ugLevel->id],
            ],
        ])->assertUnprocessable();

        $this->putJson("/api/academic/programs/{$jupeb->id}/courses", [
            'courses' => [
                ['course_id' => $course->id, 'academic_level_id' => $jupebLevel->id],
            ],
        ])->assertOk()
            ->assertJsonPath('courses.0.pivot.academic_level_id', $jupebLevel->id);
    }

    public function test_jupeb_student_matches_jupeb_level_not_undergraduate_100(): void
    {
        [, $program, $course, $ugLevel, $term] = $this->seedCatalog();
        $jupeb = Program::query()->create([
            'department_id' => $program->department_id,
            'name' => 'Architecture JUPEB',
            'code' => 'ARC-JPB',
            'award_type' => 'JUPEB',
            'study_level' => 'jupeb',
            'entry_modes' => ['jupeb'],
            'duration_years' => 1,
            'is_active' => true,
        ]);
        $jupebLevel = AcademicLevel::query()->create([
            'name' => 'JUPEB Year 1',
            'code' => '100',
            'study_level' => 'jupeb',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $jupeb->courses()->sync([
            $course->id => ['academic_level_id' => $jupebLevel->id, 'bucket' => 'departmental'],
        ]);
        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $student = $this->studentOn($jupeb, 'jupeb');
        $service = app(CourseRegistrationService::class);

        $this->assertSame($jupebLevel->id, $service->studentLevel($student)?->id);
        $this->assertNotSame($ugLevel->id, $service->studentLevel($student)?->id);
        $this->assertContains($offering->id, $service->availableOfferings($student, $term)->pluck('id')->all());
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

    private function studentOn(Program $program, string $studyLevel = 'undergraduate'): Student
    {
        $user = User::factory()->create(['status' => 'active']);

        return Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'current_level' => $studyLevel === 'postgraduate' ? 1 : 100,
            'study_level' => $studyLevel,
            'status' => 'active',
        ])->load(['program.department', 'application']);
    }
}
