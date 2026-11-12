<?php

namespace Tests\Feature;

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
use App\Support\GradeStatus;
use App\Support\PermissionCatalog;
use Database\Seeders\GradingScaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResultAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private AcademicTerm $term;

    private Department $csDepartment;

    private Department $artsDepartment;

    private Faculty $scienceFaculty;

    private Faculty $artsFaculty;

    private CourseOffering $csOffering;

    private CourseOffering $artsOffering;

    private CourseOffering $facultyLaneOffering;

    private Student $student;

    private OfficeDepartment $office;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $this->seed(GradingScaleSeeder::class);

        $this->office = OfficeDepartment::query()->create([
            'name' => 'Exams',
            'code' => 'EXM',
            'is_active' => true,
        ]);
        $this->office->syncNavKeys([
            'results', 'results-students', 'results-import', 'results-department',
            'results-college', 'results-approvals', 'offerings', 'courses',
        ]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $this->scienceFaculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $this->artsFaculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Arts']);
        $this->csDepartment = Department::query()->create(['faculty_id' => $this->scienceFaculty->id, 'name' => 'CS']);
        $this->artsDepartment = Department::query()->create(['faculty_id' => $this->artsFaculty->id, 'name' => 'English']);

        $program = Program::query()->create([
            'department_id' => $this->csDepartment->id,
            'name' => 'B.Sc CS',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $session = AcademicSession::query()->create([
            'label' => '2024/2025',
            'starts_on' => '2024-10-01',
            'ends_on' => '2025-09-30',
        ]);
        $this->term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2024/2025',
            'is_current' => true,
        ]);

        $csCourse = Course::query()->create([
            'department_id' => $this->csDepartment->id,
            'code' => 'CSC101',
            'title' => 'Intro',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $this->csOffering = CourseOffering::query()->create([
            'course_id' => $csCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);

        $facultyCourse = Course::query()->create([
            'department_id' => $this->csDepartment->id,
            'code' => 'SCI200',
            'title' => 'Faculty seminar',
            'units' => 2,
            'course_type' => 'faculty',
            'status' => 'core',
        ]);
        $this->facultyLaneOffering = CourseOffering::query()->create([
            'course_id' => $facultyCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);

        $artsCourse = Course::query()->create([
            'department_id' => $this->artsDepartment->id,
            'code' => 'ENG101',
            'title' => 'Poetry',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $this->artsOffering = CourseOffering::query()->create([
            'course_id' => $artsCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);

        $this->student = Student::query()->create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BU/2020/001',
            'student_number' => 'STU001',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $this->csOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $this->facultyLaneOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $this->artsOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
    }

    public function test_lecturer_can_draft_and_import_only_assigned_offerings(): void
    {
        $lecturer = $this->makeScopedOfficer('lecturer', null, [
            'results.read', 'results.write', 'results.import',
        ]);
        $staffId = (int) $lecturer->staff->id;
        $this->csOffering->syncLecturers([$staffId]);

        Sanctum::actingAs($lecturer);

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->csOffering->id,
            'score' => 70,
        ])->assertOk()->assertJsonPath('status', GradeStatus::DRAFT);

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->artsOffering->id,
            'score' => 70,
        ])->assertForbidden();

        $this->postJson('/api/academic/results/import', [
            'course_offering_id' => $this->csOffering->id,
            'score_component' => 'total',
            'csv' => "matric,score\nBU/2020/001,71",
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/import', [
            'course_offering_id' => $this->artsOffering->id,
            'score_component' => 'total',
            'csv' => "matric,score\nBU/2020/001,71",
        ])->assertForbidden();

        $offerings = $this->getJson('/api/academic/results/offerings')->assertOk()->json();
        $ids = collect($offerings)->pluck('id')->all();
        $this->assertEquals([$this->csOffering->id], $ids);

        $this->getJson('/api/academic/results/meta')
            ->assertOk()
            ->assertJsonPath('scope.kind', 'lecturer');

        $students = $this->getJson('/api/academic/results/students?search=BU/2020/001')->assertOk()->json('data');
        $this->assertCount(1, $students);

        $byCourse = $this->getJson('/api/academic/results/students?course_id='.$this->csOffering->course_id)
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byCourse);

        $otherCourse = $this->getJson('/api/academic/results/students?course_id='.$this->artsOffering->course_id)
            ->assertOk()
            ->json('data');
        $this->assertCount(0, $otherCourse);

        $otherStudent = Student::query()->create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'program_id' => $this->student->program_id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'matric_number' => 'BU/2020/999',
            'student_number' => 'STU999',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        $hidden = $this->getJson('/api/academic/results/students?search=BU/2020/999')->assertOk()->json('data');
        $this->assertCount(0, $hidden);
        $this->assertSame('BU/2020/999', $otherStudent->matric_number);
    }

    public function test_co_lecturer_on_same_offering_can_enter_results(): void
    {
        $primary = $this->makeScopedOfficer('lecturer', null, [
            'results.read', 'results.write', 'results.import',
            'academic.offerings.manage', 'academic.courses.manage',
        ]);
        $co = $this->makeScopedOfficer('lecturer', null, [
            'results.read', 'results.write', 'results.import',
            'academic.offerings.manage', 'academic.courses.manage',
        ]);
        $this->csOffering->syncLecturers([(int) $primary->staff->id, (int) $co->staff->id]);

        Sanctum::actingAs($co);
        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->csOffering->id,
            'score' => 68,
        ])->assertOk()->assertJsonPath('status', GradeStatus::DRAFT);

        $offerings = $this->getJson('/api/academic/offerings')->assertOk()->json();
        $this->assertEquals([$this->csOffering->id], collect($offerings)->pluck('id')->all());

        $courses = $this->getJson('/api/academic/courses')->assertOk()->json();
        $this->assertEquals(
            [$this->csOffering->course_id],
            collect($courses)->pluck('id')->all(),
        );

        $this->postJson('/api/academic/offerings', [
            'course_id' => $this->csOffering->course_id,
            'academic_term_id' => $this->term->id,
            'section' => 'B',
        ])->assertForbidden();

        $this->patchJson('/api/academic/offerings/'.$this->csOffering->id, [
            'capacity' => 40,
        ])->assertForbidden();

        $this->deleteJson('/api/academic/offerings/'.$this->csOffering->id)
            ->assertForbidden();
    }

    public function test_hod_sees_department_grades_across_lanes_not_other_departments(): void
    {
        $hod = $this->makeScopedOfficer('department-hod', $this->csDepartment->id, [
            'results.read', 'results.write', 'results.import', 'results.department_submit',
        ]);
        Sanctum::actingAs($hod);

        $csGrade = $this->createDraft($this->csOffering);
        $facultyGrade = $this->createDraft($this->facultyLaneOffering);
        $artsGrade = $this->createDraft($this->artsOffering);

        $list = $this->getJson('/api/academic/results/grades?status=draft')->assertOk()->json();
        $ids = collect($list['data'] ?? $list)->pluck('id')->all();

        $this->assertContains($csGrade->id, $ids);
        $this->assertContains($facultyGrade->id, $ids);
        $this->assertNotContains($artsGrade->id, $ids);

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->facultyLaneOffering->id,
            'score' => 66,
        ])->assertOk();

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->artsOffering->id,
            'score' => 66,
        ])->assertForbidden();
    }

    public function test_dean_sees_college_grades_not_other_colleges(): void
    {
        $dean = $this->makeScopedOfficer('college-dean', $this->csDepartment->id, [
            'results.read', 'results.write', 'results.import', 'results.department_submit', 'results.submit',
        ]);
        Sanctum::actingAs($dean);

        $csGrade = $this->createDraft($this->csOffering);
        $artsGrade = $this->createDraft($this->artsOffering);

        $list = $this->getJson('/api/academic/results/grades?status=draft')->assertOk()->json();
        $ids = collect($list['data'] ?? $list)->pluck('id')->all();
        $this->assertContains($csGrade->id, $ids);
        $this->assertNotContains($artsGrade->id, $ids);

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->csOffering->id,
            'score' => 68,
        ])->assertOk();

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->artsOffering->id,
            'score' => 68,
        ])->assertForbidden();
    }

    public function test_committee_of_deans_list_is_campus_wide(): void
    {
        $committee = $this->makeScopedOfficer('committee-of-deans', null, [
            'results.read', 'results.faculty_approve',
        ]);
        Sanctum::actingAs($committee);

        $csGrade = Grade::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $this->csOffering->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::SUBMITTED,
            'upload_lane' => GradeStatus::LANE_DEPARTMENTAL,
            'faculty_id' => $this->scienceFaculty->id,
            'department_id' => $this->csDepartment->id,
        ]);
        $artsGrade = Grade::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $this->artsOffering->id,
            'sitting' => 'main',
            'score' => 65,
            'letter' => 'B',
            'points' => 4,
            'status' => GradeStatus::SUBMITTED,
            'upload_lane' => GradeStatus::LANE_DEPARTMENTAL,
            'faculty_id' => $this->artsFaculty->id,
            'department_id' => $this->artsDepartment->id,
        ]);

        $list = $this->getJson('/api/academic/results/grades?status='.GradeStatus::SUBMITTED)->assertOk()->json();
        $ids = collect($list['data'] ?? $list)->pluck('id')->all();
        $this->assertContains($csGrade->id, $ids);
        $this->assertContains($artsGrade->id, $ids);
    }

    public function test_exam_officer_global_behaviour_still_passes(): void
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'exam-officer'],
            ['name' => 'Exam Officer', 'is_system' => true, 'is_active' => true],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                'results.read', 'results.write', 'results.import', 'results.department_submit',
                'results.submit', 'results.faculty_approve', 'results.board', 'results.release',
            ])->pluck('id')
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'EX-GLOBAL',
            'office_department_id' => $this->office->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->artsOffering->id,
            'score' => 72,
        ])->assertOk();

        $offerings = $this->getJson('/api/academic/results/offerings')->assertOk()->json();
        $ids = collect($offerings)->pluck('id')->all();
        $this->assertContains($this->csOffering->id, $ids);
        $this->assertContains($this->artsOffering->id, $ids);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function makeScopedOfficer(string $slug, ?int $departmentId, array $permissionKeys): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => str_replace('-', ' ', $slug),
                'is_system' => true,
                'is_active' => true,
            ],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id')
        );

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'EX-'.$user->id,
            'office_department_id' => $this->office->id,
            'department_id' => $departmentId,
        ]);

        return $user->fresh(['staff', 'roles']);
    }

    private function createDraft(CourseOffering $offering): Grade
    {
        $offering->loadMissing('course.department');

        return Grade::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $offering->id,
            'sitting' => 'main',
            'score' => 60,
            'letter' => 'B',
            'points' => 4,
            'status' => GradeStatus::DRAFT,
            'upload_lane' => $offering->course?->course_type === 'faculty'
                ? GradeStatus::LANE_FACULTY
                : GradeStatus::LANE_DEPARTMENTAL,
            'faculty_id' => $offering->course?->department?->faculty_id,
            'department_id' => $offering->course?->department_id,
        ]);
    }
}
