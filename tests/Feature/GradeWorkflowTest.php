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
use App\Support\GradeStatus;
use App\Support\PermissionCatalog;
use App\Support\ResultImportColumns;
use Database\Seeders\GradingScaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GradeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $staffUser;

    private Student $student;

    private Enrollment $enrollment;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $this->seed(GradingScaleSeeder::class);

        $keys = [
            'results.read', 'results.write', 'results.submit', 'results.faculty_approve',
            'results.board', 'results.release', 'results.import', 'scales.manage',
        ];
        $role = Role::query()->firstOrCreate(
            ['slug' => 'exam-officer'],
            ['name' => 'Exam Officer', 'is_system' => true, 'is_active' => true],
        );
        $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));

        $this->staffUser = User::factory()->create(['status' => 'active']);
        $this->staffUser->roles()->attach($role->id);
        $office = OfficeDepartment::query()->create([
            'name' => 'Exams',
            'code' => 'EXM',
            'is_active' => true,
        ]);
        $office->syncNavKeys([
            'results', 'results-students', 'results-import', 'results-approvals',
            'results-board', 'results-release', 'results-grading-scale',
        ]);
        Staff::query()->create([
            'user_id' => $this->staffUser->id,
            'staff_number' => 'EX001',
            'office_department_id' => $office->id,
        ]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'CS']);
        $program = Program::query()->create([
            'department_id' => $department->id,
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
        $course = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'CSC101',
            'title' => 'Intro',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);

        $studentUser = User::factory()->create(['status' => 'active']);
        $this->student = Student::query()->create([
            'user_id' => $studentUser->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BU/2020/001',
            'student_number' => 'STU001',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        $this->enrollment = Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $offering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        $this->enrollment->load('offering.course.department');
    }

    public function test_draft_submit_approve_clear_release_and_student_transcript(): void
    {
        Sanctum::actingAs($this->staffUser);

        $create = $this->postJson('/api/academic/results/grades', [
            'enrollment_id' => $this->enrollment->id,
            'score' => 72,
        ]);
        $create->assertOk();
        $gradeId = $create->json('id');
        $this->assertEquals(GradeStatus::DRAFT, $create->json('status'));
        $this->assertEquals('A', $create->json('letter'));

        $this->postJson('/api/academic/results/submit', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/faculty-approve', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/board-scopes/clear', [
            'academic_term_id' => $this->term->id,
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/release', [
            'academic_term_id' => $this->term->id,
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertEquals(GradeStatus::RELEASED, Grade::query()->find($gradeId)?->status);

        Sanctum::actingAs($this->student->user);
        $transcript = $this->getJson('/api/academic/transcript');
        $transcript->assertOk();
        $this->assertEquals(5.0, (float) $transcript->json('cgpa'));
        $this->assertNotEmpty($transcript->json('rows'));
        $transcript->assertJsonPath('official', false);
        $transcript->assertJsonPath('can_sign', false);
        $this->assertStringContainsString('not signed', (string) $transcript->json('notice'));

        $html = $this->get('/api/academic/transcript?format=html', ['Accept' => 'text/html']);
        $html->assertOk();
        $html->assertSee('UNOFFICIAL — FOR STUDENT VIEWING ONLY', false);
        $html->assertSee('not signed', false);
        $html->assertDontSee('Signature', false);
        $html->assertDontSee('Registrar', false);
    }

    public function test_student_transcript_hides_unreleased_grades(): void
    {
        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 55,
            'letter' => 'C',
            'points' => 3,
            'status' => GradeStatus::DRAFT,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        Sanctum::actingAs($this->student->user);
        $this->getJson('/api/academic/transcript')
            ->assertOk()
            ->assertJsonPath('cgpa', 0)
            ->assertJsonPath('rows', [])
            ->assertJsonPath('can_sign', false)
            ->assertJsonPath('official', false);

        $enrollments = $this->getJson('/api/academic/my-enrollments')->assertOk()->json();
        $this->assertTrue(collect($enrollments)->contains(fn ($row) => ($row['pending_grade'] ?? false) === true));
    }

    public function test_printable_submission_list_returns_structure(): void
    {
        Sanctum::actingAs($this->staffUser);
        $grade = Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::SUBMITTED,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        $this->getJson('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=submitted')
            ->assertOk()
            ->assertJsonPath('layout', 'student_matrix')
            ->assertJsonPath('students.0.matric', 'BU/2020/001')
            ->assertJsonPath('course_columns.0.code', 'CSC101')
            ->assertJsonPath('course_columns.0.header_meta', '3:C');

        $html = $this->get('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=submitted&format=html')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->getContent();
        $this->assertStringContainsString('BU/2020/001', $html);
        $this->assertStringContainsString('TUR Current Semester', $html);
        $this->assertStringContainsString('CSC101', $html);

        $this->getJson('/api/academic/results/reports/submission-list/department?format=html')
            ->assertStatus(422)
            ->assertJsonValidationErrors('academic_term_id');

        $this->get('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&format=pdf')
            ->assertStatus(422);

        $pdf = $this->get('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&department_id='.$this->enrollment->offering->course->department_id.'&level=100&status=submitted&format=pdf');
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');

        $this->assertNotNull($grade->id);
    }

    public function test_board_list_includes_general_lane_rows_for_faculty_scope(): void
    {
        Sanctum::actingAs($this->staffUser);
        $facultyId = (int) $this->enrollment->offering->course->department->faculty_id;
        $campusId = (int) Faculty::query()->whereKey($facultyId)->value('campus_id');
        $otherFaculty = Faculty::query()->create([
            'campus_id' => $campusId,
            'name' => 'General Studies',
        ]);
        $gsDept = Department::query()->create(['faculty_id' => $otherFaculty->id, 'name' => 'GST']);
        $gsCourse = Course::query()->create([
            'department_id' => $gsDept->id,
            'code' => 'GST101',
            'title' => 'Use of English',
            'units' => 2,
            'course_type' => 'general',
            'status' => 'core',
        ]);
        $gsOffering = CourseOffering::query()->create([
            'course_id' => $gsCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);
        $gsEnrollment = Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $gsOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);

        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::BOARD_READY,
            'upload_lane' => GradeStatus::LANE_DEPARTMENTAL,
            'faculty_id' => $facultyId,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);
        Grade::query()->create([
            'enrollment_id' => $gsEnrollment->id,
            'sitting' => 'main',
            'score' => 60,
            'letter' => 'B',
            'points' => 4,
            'status' => GradeStatus::BOARD_READY,
            'upload_lane' => GradeStatus::LANE_GENERAL,
            'faculty_id' => $otherFaculty->id,
            'department_id' => $gsDept->id,
        ]);

        $report = $this->getJson(
            '/api/academic/results/board-lists/faculty?academic_term_id='.$this->term->id
            .'&faculty_id='.$facultyId
            .'&status=board_ready'
            .'&level=100'
        )->assertOk();

        $this->assertSame('board_summary', $report->json('layout'));
        $deptNames = collect($report->json('departments'))->pluck('name')->implode(' ');
        $this->assertStringContainsString('Department of CS', $deptNames);
        $this->assertStringContainsString('Department of GST', $deptNames);
        $this->assertNotEmpty($report->json('departments.0.students.0.name'));
    }

    public function test_staff_can_download_results_import_template(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->get('/api/academic/results/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame(['matric', 'ca', 'exam', 'score'], ResultImportColumns::all());
    }

    public function test_import_applies_sitting_from_the_request(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->postJson('/api/academic/results/import', [
            'course_offering_id' => $this->enrollment->course_offering_id,
            'score_component' => 'total',
            'sitting' => 'supplementary',
            'csv' => "matric,ca,exam,score\nBU/2020/001,28,44,",
        ])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('grades', [
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'supplementary',
        ]);
    }

    public function test_cannot_edit_after_submit(): void
    {
        Sanctum::actingAs($this->staffUser);
        $grade = Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 60,
            'letter' => 'B',
            'points' => 4,
            'status' => GradeStatus::SUBMITTED,
        ]);

        $this->patchJson('/api/academic/results/grades/'.$grade->id, ['score' => 80])
            ->assertStatus(422);
    }

    public function test_department_filter_includes_drafts_when_org_snapshot_is_missing(): void
    {
        Sanctum::actingAs($this->staffUser);
        $departmentId = (int) $this->enrollment->offering->course->department_id;
        $grade = Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 66,
            'letter' => 'B',
            'points' => 4,
            'status' => GradeStatus::DRAFT,
            'department_id' => null,
            'faculty_id' => null,
        ]);
        $this->assertNull($grade->fresh()->department_id);

        $this->getJson('/api/academic/results/grades?status=draft&department_id='.$departmentId.'&per_page=5000')
            ->assertOk()
            ->assertJsonFragment(['id' => $grade->id]);

        $this->getJson(
            '/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id
            .'&department_id='.$departmentId
            .'&status=draft'
        )
            ->assertOk()
            ->assertJsonPath('layout', 'student_matrix')
            ->assertJsonPath('students.0.matric', 'BU/2020/001');
    }

    public function test_draft_queue_filters_use_offering_and_student_on_the_grade(): void
    {
        Sanctum::actingAs($this->staffUser);
        AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $gradeId = $this->postJson('/api/academic/results/grades', [
            'enrollment_id' => $this->enrollment->id,
            'score' => 71,
        ])->assertOk()->json('id');

        $departmentId = (int) $this->enrollment->offering->course->department_id;
        $facultyId = (int) $this->enrollment->offering->course->department->faculty_id;

        $this->getJson('/api/academic/results/grades?'.http_build_query([
            'status' => 'draft',
            'academic_term_id' => $this->term->id,
            'academic_session_id' => $this->term->academic_session_id,
            'level' => '100 Level',
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
            'per_page' => 5000,
        ]))
            ->assertOk()
            ->assertJsonFragment(['id' => $gradeId]);
    }

    public function test_department_officer_cannot_write_outside_departmental_lane(): void
    {
        $officer = $this->makeScopedOfficer('department-exam-officer', (int) $this->enrollment->offering->course->department_id);
        Sanctum::actingAs($officer);

        $ok = $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->enrollment->course_offering_id,
            'score' => 64,
        ]);
        $ok->assertOk();
        $this->assertEquals(GradeStatus::DRAFT, $ok->json('status'));

        $facultyCourse = Course::query()->create([
            'department_id' => $this->enrollment->offering->course->department_id,
            'code' => 'FAC101',
            'title' => 'Faculty seminar',
            'units' => 2,
            'course_type' => 'faculty',
            'status' => 'core',
        ]);
        $facultyOffering = CourseOffering::query()->create([
            'course_id' => $facultyCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);

        $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $facultyOffering->id,
            'score' => 70,
        ])->assertForbidden();
    }

    public function test_import_holds_unregistered_students_until_they_enrol(): void
    {
        Sanctum::actingAs($this->staffUser);

        $heldStudent = Student::query()->create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'program_id' => $this->student->program_id,
            'first_name' => 'Held',
            'last_name' => 'Student',
            'matric_number' => 'BU/2020/099',
            'student_number' => 'STU099',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        $this->postJson('/api/academic/results/import', [
            'course_offering_id' => $this->enrollment->course_offering_id,
            'score_component' => 'total',
            'csv' => "matric,score\nBU/2020/099,61",
        ])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $grade = Grade::query()
            ->where('student_id', $heldStudent->id)
            ->where('course_offering_id', $this->enrollment->course_offering_id)
            ->first();
        $this->assertNotNull($grade);
        $this->assertNull($grade->enrollment_id);
        $this->assertTrue($grade->registration_held);
        $this->assertEquals(GradeStatus::DRAFT, $grade->status);

        $this->postJson('/api/academic/results/submit', ['ids' => [$grade->id]])
            ->assertOk()
            ->assertJsonPath('updated', 0);

        $enrollment = Enrollment::query()->create([
            'student_id' => $heldStudent->id,
            'course_offering_id' => $this->enrollment->course_offering_id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        Grade::attachEnrollment($enrollment);
        $grade->refresh();
        $this->assertFalse($grade->registration_held);
        $this->assertEquals($enrollment->id, $grade->enrollment_id);

        $this->postJson('/api/academic/results/submit', ['ids' => [$grade->id]])
            ->assertOk()
            ->assertJsonPath('updated', 1);
    }

    public function test_faculty_officer_may_approve_general_lane_outside_faculty(): void
    {
        $scienceDeptId = (int) $this->enrollment->offering->course->department_id;
        $officer = $this->makeScopedOfficer('faculty-exam-officer', $scienceDeptId);
        Sanctum::actingAs($officer);

        $campusId = (int) Faculty::query()->whereKey($this->enrollment->offering->course->department->faculty_id)->value('campus_id');
        $otherFaculty = Faculty::query()->create(['campus_id' => $campusId, 'name' => 'Arts']);
        $artsDept = Department::query()->create(['faculty_id' => $otherFaculty->id, 'name' => 'English']);
        $gsDept = Department::query()->create([
            'faculty_id' => Faculty::query()->create(['campus_id' => $campusId, 'name' => 'GST Faculty'])->id,
            'name' => 'GST',
        ]);
        $gsCourse = Course::query()->create([
            'department_id' => $gsDept->id,
            'code' => 'GST201',
            'title' => 'Peace',
            'units' => 2,
            'course_type' => 'general',
            'status' => 'core',
        ]);
        $gsOffering = CourseOffering::query()->create([
            'course_id' => $gsCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);
        $gsEnrollment = Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $gsOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        $gsGrade = Grade::query()->create([
            'enrollment_id' => $gsEnrollment->id,
            'sitting' => 'main',
            'score' => 58,
            'letter' => 'C',
            'points' => 3,
            'status' => GradeStatus::SUBMITTED,
            'upload_lane' => GradeStatus::LANE_GENERAL,
            'faculty_id' => $gsDept->faculty_id,
            'department_id' => $gsDept->id,
        ]);

        $this->postJson('/api/academic/results/faculty-approve', ['ids' => [$gsGrade->id]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $artsCourse = Course::query()->create([
            'department_id' => $artsDept->id,
            'code' => 'ENG101',
            'title' => 'Poetry',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $artsOffering = CourseOffering::query()->create([
            'course_id' => $artsCourse->id,
            'academic_term_id' => $this->term->id,
            'section' => 'A',
            'capacity' => 50,
        ]);
        $artsEnrollment = Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $artsOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        $artsGrade = Grade::query()->create([
            'enrollment_id' => $artsEnrollment->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::SUBMITTED,
            'upload_lane' => GradeStatus::LANE_DEPARTMENTAL,
            'faculty_id' => $otherFaculty->id,
            'department_id' => $artsDept->id,
        ]);

        $this->postJson('/api/academic/results/faculty-approve', ['ids' => [$artsGrade->id]])
            ->assertForbidden();
    }

    public function test_staff_student_detail_accepts_offering_picker_and_returns_gpa_audit(): void
    {
        Sanctum::actingAs($this->staffUser);

        $create = $this->postJson('/api/academic/results/grades', [
            'student_id' => $this->student->id,
            'course_offering_id' => $this->enrollment->course_offering_id,
            'ca_score' => 28,
            'exam_score' => 44,
            'sitting' => 'main',
        ]);
        $create->assertOk();
        $this->assertEquals('A', $create->json('letter'));
        $this->assertFalse((bool) $create->json('registration_held'));

        $detail = $this->getJson('/api/academic/results/students/'.$this->student->id);
        $detail->assertOk();
        $detail->assertJsonPath('student.matric_number', 'BU/2020/001');
        $this->assertNotNull($detail->json('gpa'));
        $this->assertNotNull($detail->json('cgpa'));
        $this->assertNotEmpty($detail->json('grades'));
        $this->assertIsArray($detail->json('audit'));
        $this->assertIsArray($detail->json('transcript'));

        $offerings = $this->getJson('/api/academic/results/offerings');
        $offerings->assertOk();
        $this->assertNotEmpty($offerings->json());
    }

    private function makeScopedOfficer(string $slug, int $departmentId): User
    {
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $office = OfficeDepartment::query()->where('code', 'EXM')->firstOrFail();
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'EX-'.$user->id,
            'office_department_id' => $office->id,
            'department_id' => $departmentId,
        ]);

        return $user;
    }
}
