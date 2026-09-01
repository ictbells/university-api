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
            'results.read', 'results.write', 'results.department_submit', 'results.submit', 'results.faculty_approve',
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
            'results', 'results-students', 'results-import', 'results-department', 'results-college', 'results-approvals',
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

        $this->postJson('/api/academic/results/department-submit', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/submit', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/faculty-approve', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->postJson('/api/academic/results/board-scopes/clear', [
            'academic_term_id' => $this->term->id,
            'ids' => [$gradeId],
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
        $transcript->assertJsonPath('rows.0.letter', 'A');
        $this->assertEquals(72, (float) $transcript->json('rows.0.score'));
        $transcript->assertJsonPath('rows.0.grade_obtained', '72(A)');
        $transcript->assertJsonPath('terms.0.heading', 'FIRST SEMESTER 2024/2025 100');
        $transcript->assertJsonPath('terms.0.credits_offered', 3);
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

    public function test_college_cannot_skip_the_department_step_and_can_return_to_department(): void
    {
        Sanctum::actingAs($this->staffUser);

        $gradeId = $this->postJson('/api/academic/results/grades', [
            'enrollment_id' => $this->enrollment->id,
            'score' => 64,
        ])->assertOk()->json('id');

        $this->postJson('/api/academic/results/submit', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 0);
        $this->assertEquals(GradeStatus::DRAFT, Grade::query()->find($gradeId)?->status);

        $this->postJson('/api/academic/results/department-submit', ['ids' => [$gradeId]])
            ->assertOk()
            ->assertJsonPath('updated', 1);
        $this->assertEquals(GradeStatus::DEPARTMENT_SUBMITTED, Grade::query()->find($gradeId)?->status);

        $this->postJson('/api/academic/results/college-return', [
            'ids' => [$gradeId],
            'note' => 'Fix CA total',
        ])->assertOk()->assertJsonPath('updated', 1);

        $returned = Grade::query()->find($gradeId);
        $this->assertEquals(GradeStatus::CORRECTION_REQUIRED, $returned?->status);
        $this->assertEquals('Fix CA total', $returned?->correction_note);
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

    public function test_student_transcript_shows_letter_and_score_when_letter_was_not_stored(): void
    {
        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'student_id' => $this->student->id,
            'course_offering_id' => $this->enrollment->course_offering_id,
            'sitting' => 'main',
            'score' => 72,
            'letter' => null,
            'points' => 0,
            'status' => GradeStatus::RELEASED,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        Sanctum::actingAs($this->student->user);
        $transcript = $this->getJson('/api/academic/transcript')->assertOk();
        $this->assertEquals('A', $transcript->json('terms.0.rows.0.letter'));
        $this->assertEquals(72, (float) $transcript->json('terms.0.rows.0.score'));
        $this->assertEquals(5.0, (float) $transcript->json('cgpa'));

        $enrollments = $this->getJson('/api/academic/my-enrollments')->assertOk()->json();
        $row = collect($enrollments)->firstWhere('id', $this->enrollment->id);
        $this->assertEquals('A', $row['grade']['letter'] ?? null);
        $this->assertEquals(72, (float) ($row['grade']['score'] ?? 0));
    }

    public function test_student_unsigned_transcript_lists_registered_courses_and_hides_unreleased_scores(): void
    {
        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'student_id' => $this->student->id,
            'course_offering_id' => $this->enrollment->course_offering_id,
            'sitting' => 'main',
            'score' => 55,
            'letter' => 'C',
            'points' => 3,
            'status' => GradeStatus::DRAFT,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        Sanctum::actingAs($this->student->user);
        $payload = $this->getJson('/api/academic/unsigned-transcript')->assertOk();
        $payload->assertJsonPath('unsigned', true);
        $payload->assertJsonPath('official', false);
        $payload->assertJsonPath('can_sign', false);
        $payload->assertJsonPath('terms.0.rows.0.course.code', 'CSC101');
        $payload->assertJsonPath('terms.0.rows.0.result_status', 'pending');
        $payload->assertJsonPath('terms.0.rows.0.score', null);
        $payload->assertJsonPath('terms.0.rows.0.letter', null);
        $this->assertNull($payload->json('gpa'));

        $html = $this->get('/api/academic/unsigned-transcript?format=html', ['Accept' => 'text/html']);
        $html->assertOk();
        $html->assertSee('UNSIGNED — FOR STUDENT VIEWING ONLY', false);
        $html->assertSee('CSC101', false);
        $html->assertSee('Pending', false);
        $html->assertSee('Office of the Registrar', false);
        $html->assertSee('Bells University of Technology', false);
        $html->assertDontSee('>CA</th>', false);
        $html->assertDontSee('>Exam</th>', false);
    }

    public function test_student_unsigned_transcript_filters_by_session_and_semester(): void
    {
        $session = $this->term->session;
        $secondTerm = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Second',
            'session_label' => $session->label,
            'is_current' => false,
        ]);
        $otherSession = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        $otherTerm = AcademicTerm::query()->create([
            'academic_session_id' => $otherSession->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => false,
        ]);

        $secondCourse = Course::query()->create([
            'department_id' => $this->enrollment->offering->course->department_id,
            'code' => 'CSC102',
            'title' => 'Second semester',
            'units' => 2,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $otherCourse = Course::query()->create([
            'department_id' => $this->enrollment->offering->course->department_id,
            'code' => 'CSC201',
            'title' => 'Next session',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $secondOffering = CourseOffering::query()->create([
            'course_id' => $secondCourse->id,
            'academic_term_id' => $secondTerm->id,
            'section' => 'A',
            'capacity' => 50,
        ]);
        $otherOffering = CourseOffering::query()->create([
            'course_id' => $otherCourse->id,
            'academic_term_id' => $otherTerm->id,
            'section' => 'A',
            'capacity' => 50,
        ]);
        Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $secondOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);
        Enrollment::query()->create([
            'student_id' => $this->student->id,
            'course_offering_id' => $otherOffering->id,
            'status' => 'enrolled',
            'registered_at' => now(),
        ]);

        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'student_id' => $this->student->id,
            'course_offering_id' => $this->enrollment->course_offering_id,
            'sitting' => 'main',
            'score' => 72,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::RELEASED,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        Sanctum::actingAs($this->student->user);

        $semester = $this->getJson('/api/academic/unsigned-transcript?academic_term_id='.$this->term->id)->assertOk();
        $this->assertCount(1, $semester->json('terms'));
        $semester->assertJsonPath('terms.0.rows.0.course.code', 'CSC101');
        $semester->assertJsonPath('terms.0.rows.0.result_status', 'released');
        $semester->assertJsonPath('terms.0.rows.0.letter', 'A');
        $this->assertEquals(72, (float) $semester->json('terms.0.rows.0.score'));
        $this->assertEquals(5.0, (float) $semester->json('gpa'));
        $this->assertStringContainsString('First', (string) $semester->json('scope_label'));

        $sessionPayload = $this->getJson('/api/academic/unsigned-transcript?academic_session_id='.$session->id)->assertOk();
        $codes = collect($sessionPayload->json('terms'))->flatMap(fn ($term) => collect($term['rows'])->pluck('course.code'))->all();
        $this->assertEqualsCanonicalizing(['CSC101', 'CSC102'], $codes);
        $this->assertStringContainsString('all semesters', (string) $sessionPayload->json('scope_label'));

        $html = $this->get(
            '/api/academic/unsigned-transcript?academic_term_id='.$this->term->id.'&format=html',
            ['Accept' => 'text/html'],
        );
        $html->assertOk();
        $html->assertSee('CSC101', false);
        $html->assertDontSee('CSC102', false);
        $html->assertDontSee('CSC201', false);
        $html->assertSee('72', false);
        $html->assertSee('A', false);
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
            ->assertJsonPath('layout', 'broadsheet')
            ->assertJsonPath('students.0.matric', 'BU/2020/001')
            ->assertJsonPath('students.0.name', 'LOVELACE Ada')
            ->assertJsonPath('students.0.status', 'GS')
            ->assertJsonPath('course_columns.0.code', 'CSC101')
            ->assertJsonPath('course_columns.0.header_meta', '(3) (C)');

        $html = html_entity_decode(
            $this->get('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=submitted&format=html')
                ->assertOk()
                ->assertHeader('content-type', 'text/html; charset=UTF-8')
                ->getContent(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $this->assertStringContainsString('BU/2020/001', $html);
        $this->assertStringContainsString('UNDERGRADUATE SEMESTER RESULT', $html);
        $this->assertStringContainsString('LOVELACE Ada', $html);
        $this->assertStringContainsString('TUT', $html);
        $this->assertStringContainsString('SGPA', $html);
        $this->assertStringContainsString('>GS<', $html);
        $this->assertStringContainsString("HOD's Signature and Date", $html);
        $this->assertStringNotContainsString("Dean's Signature and Date", $html);
        $this->assertStringContainsString('CSC101', $html);
        $this->assertStringContainsString('70 (A)', $html);

        $facultyId = (int) $this->enrollment->offering->course->department->faculty_id;
        $collegeHtml = html_entity_decode($this->get(
            '/api/academic/results/reports/submission-list/faculty?academic_term_id='.$this->term->id
            .'&faculty_id='.$facultyId.'&status=submitted&format=html&step=college'
        )->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString("Dean's Signature and Date", $collegeHtml);
        $this->assertStringNotContainsString('Chairman CODD', $collegeHtml);
        $this->assertStringNotContainsString("Senate's Approval", $collegeHtml);

        $deansHtml = html_entity_decode($this->get(
            '/api/academic/results/reports/submission-list/faculty?academic_term_id='.$this->term->id
            .'&faculty_id='.$facultyId.'&status=submitted&format=html&step=deans'
        )->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Chairman CODD Signature and Date', $deansHtml);
        $this->assertStringNotContainsString("Senate's Approval", $deansHtml);

        $senateHtml = html_entity_decode($this->get(
            '/api/academic/results/board-lists/faculty?academic_term_id='.$this->term->id
            .'&faculty_id='.$facultyId.'&status=submitted&format=html'
        )->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString("Senate's Approval and Date", $senateHtml);

        $this->getJson('/api/academic/results/reports/submission-list/department?format=html')
            ->assertStatus(422)
            ->assertJsonValidationErrors('academic_term_id');

        $this->get('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&format=pdf')
            ->assertStatus(422);

        $pdf = $this->get('/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&department_id='.$this->enrollment->offering->course->department_id.'&level=100&status=submitted&format=pdf');
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $pdf->assertHeader('content-disposition', 'attachment; filename="department-results-2024-2025-first.pdf"');

        $this->assertNotNull($grade->id);
    }

    public function test_submission_list_computes_wgp_from_letter_when_points_are_missing(): void
    {
        Sanctum::actingAs($this->staffUser);
        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 0,
            'status' => GradeStatus::SUBMITTED,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        $this->getJson(
            '/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=submitted'
        )
            ->assertOk()
            ->assertJsonPath('students.0.matric', 'BU/2020/001')
            ->assertJsonPath('students.0.tup', '3')
            ->assertJsonPath('students.0.wgp', '15')
            ->assertJsonPath('students.0.gpa', '5.00')
            ->assertJsonPath('students.0.sgpa', '5.00')
            ->assertJsonPath('students.0.cgpa', '5.00')
            ->assertJsonPath('students.0.tuf', '0')
            ->assertJsonPath('students.0.status', 'GS');
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

        $this->assertSame('broadsheet', $report->json('layout'));
        $this->assertSame('senate', $report->json('step'));
        $deptNames = collect($report->json('sheets'))->pluck('name')->implode(' ');
        $this->assertStringContainsString('Department of CS', $deptNames);
        $this->assertStringContainsString('GST101', (string) $report->json('sheets.0.students.0.other_courses'));
        $this->assertNotEmpty($report->json('sheets.0.students.0.name'));
    }

    public function test_staff_can_download_results_import_template(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->get('/api/academic/results/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame(['matric', 'ca', 'exam', 'score'], ResultImportColumns::all());
    }

    public function test_registered_course_without_score_is_ar_without_admin_remark(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->getJson(
            '/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=draft'
        )
            ->assertOk()
            ->assertJsonPath('students.0.status', 'AR')
            ->assertJsonPath('students.0.scores.CSC101', 'AR')
            ->assertJsonPath('sheets.0.summary.incomplete', 1)
            ->assertJsonPath('sheets.0.summary.good_standing', 0)
            ->assertJsonPath('students.0.sgpa', '—');
    }

    public function test_admin_cannot_set_ar_as_a_term_remark(): void
    {
        Sanctum::actingAs($this->staffUser);
        $this->staffUser->roles()->first()?->permissions()->syncWithoutDetaching(
            \App\Models\Permission::query()->where('key', 'students.manage')->pluck('id'),
        );
        $this->staffUser->unsetRelation('roles');

        $this->postJson('/api/students/'.$this->student->id.'/term-remarks', [
            'academic_term_id' => $this->term->id,
            'type' => 'ar',
        ])->assertStatus(422);
    }

    public function test_admin_term_remark_counts_absent_on_the_broadsheet_without_department_scores(): void
    {
        Sanctum::actingAs($this->staffUser);
        $this->staffUser->roles()->first()?->permissions()->syncWithoutDetaching(
            \App\Models\Permission::query()->where('key', 'students.manage')->pluck('id'),
        );
        $this->staffUser->unsetRelation('roles');

        $this->postJson('/api/students/'.$this->student->id.'/term-remarks', [
            'academic_term_id' => $this->term->id,
            'type' => 'abs_p',
        ])->assertOk()->assertJsonPath('type', 'abs_p');

        $this->getJson(
            '/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=draft'
        )
            ->assertOk()
            ->assertJsonPath('students.0.status', 'ABS_P')
            ->assertJsonPath('sheets.0.summary.absent_with_permission', 1)
            ->assertJsonPath('sheets.0.summary.good_standing', 0)
            ->assertJsonPath('students.0.sgpa', '—');
    }

    public function test_admin_term_remark_outranks_uploaded_scores_on_the_broadsheet(): void
    {
        Sanctum::actingAs($this->staffUser);
        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::DRAFT,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);
        $this->staffUser->roles()->first()?->permissions()->syncWithoutDetaching(
            \App\Models\Permission::query()->where('key', 'students.manage')->pluck('id'),
        );
        $this->staffUser->unsetRelation('roles');

        $this->postJson('/api/students/'.$this->student->id.'/term-remarks', [
            'academic_term_id' => $this->term->id,
            'type' => 'abs_p',
        ])->assertOk();

        $report = $this->getJson(
            '/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=draft'
        )->assertOk();
        $report
            ->assertJsonPath('students.0.status', 'ABS_P')
            ->assertJsonPath('sheets.0.summary.absent_with_permission', 1)
            ->assertJsonPath('sheets.0.summary.good_standing', 0);
        $this->assertStringContainsString('70', (string) $report->json('students.0.scores.CSC101'));
    }

    public function test_term_sanction_overrides_standing_on_the_broadsheet(): void
    {
        Sanctum::actingAs($this->staffUser);
        Grade::query()->create([
            'enrollment_id' => $this->enrollment->id,
            'sitting' => 'main',
            'score' => 70,
            'letter' => 'A',
            'points' => 5,
            'status' => GradeStatus::SUBMITTED,
            'faculty_id' => $this->enrollment->offering->course->department->faculty_id,
            'department_id' => $this->enrollment->offering->course->department_id,
        ]);

        $this->staffUser->roles()->first()?->permissions()->syncWithoutDetaching(
            \App\Models\Permission::query()->where('key', 'students.manage')->pluck('id'),
        );
        $this->staffUser->unsetRelation('roles');

        $this->postJson('/api/students/'.$this->student->id.'/term-sanctions', [
            'academic_term_id' => $this->term->id,
            'type' => 'rusticated',
        ])->assertOk()->assertJsonPath('type', 'rusticated');

        $this->assertSame('rusticated', $this->student->fresh()->status);

        $this->getJson(
            '/api/academic/results/reports/submission-list/department?academic_term_id='.$this->term->id.'&status=submitted'
        )
            ->assertOk()
            ->assertJsonPath('students.0.status', 'RUS')
            ->assertJsonPath('sheets.0.summary.rusticated', 1)
            ->assertJsonPath('sheets.0.summary.good_standing', 0);
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
            ->assertJsonPath('layout', 'broadsheet')
            ->assertJsonPath('students.0.matric', 'BU/2020/001');
    }

    public function test_draft_queue_filters_use_offering_and_course_level(): void
    {
        Sanctum::actingAs($this->staffUser);
        $level100 = AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        AcademicLevel::query()->create([
            'name' => '200 Level',
            'code' => '200',
            'study_level' => 'undergraduate',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $this->student->program->courses()->attach($this->enrollment->offering->course_id, [
            'academic_level_id' => $level100->id,
        ]);
        $this->student->update(['current_level' => 200]);

        $gradeId = $this->postJson('/api/academic/results/grades', [
            'enrollment_id' => $this->enrollment->id,
            'score' => 71,
        ])->assertOk()->json('id');

        $departmentId = (int) $this->enrollment->offering->course->department_id;
        $facultyId = (int) $this->enrollment->offering->course->department->faculty_id;
        $params = [
            'status' => 'draft',
            'academic_term_id' => $this->term->id,
            'academic_session_id' => $this->term->academic_session_id,
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
            'per_page' => 5000,
        ];

        $this->getJson('/api/academic/results/grades?'.http_build_query($params + ['level' => '100 Level']))
            ->assertOk()
            ->assertJsonFragment(['id' => $gradeId]);

        $ids = collect($this->getJson('/api/academic/results/grades?'.http_build_query($params + ['level' => '200']))
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($gradeId));
    }

    public function test_exam_officer_can_load_results_filter_lookups(): void
    {
        Sanctum::actingAs($this->staffUser);
        AcademicLevel::query()->create([
            'name' => '100 Level',
            'code' => '100',
            'study_level' => 'undergraduate',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->getJson('/api/academic/results/meta')
            ->assertOk()
            ->assertJsonPath('terms.0.id', $this->term->id)
            ->assertJsonPath('terms.0.session.label', '2024/2025')
            ->assertJsonPath('levels.0.code', '100')
            ->assertJsonPath('faculties.0.name', 'Science')
            ->assertJsonPath('departments.0.name', 'CS');

        $this->getJson('/api/academic/terms')->assertForbidden();
        $this->getJson('/api/academic/levels')->assertForbidden();
    }

    public function test_exam_officer_can_load_and_update_grading_scale(): void
    {
        Sanctum::actingAs($this->staffUser);
        \App\Models\GradeBoundary::query()->delete();
        \App\Models\GradingScale::query()->delete();

        $list = $this->getJson('/api/academic/results/grading-scales')->assertOk()->json();
        $this->assertNotEmpty($list);
        $scale = $list[0];
        $this->assertEquals('A', collect($scale['boundaries'])->firstWhere('letter', 'A')['letter'] ?? null);

        $this->putJson('/api/academic/results/grading-scales/'.$scale['id'], [
            'name' => $scale['name'],
            'max_points' => 5,
            'boundaries' => [
                ['letter' => 'A', 'min_score' => 70, 'max_score' => 100, 'grade_point' => 5],
                ['letter' => 'B', 'min_score' => 60, 'max_score' => 69.99, 'grade_point' => 4],
                ['letter' => 'F', 'min_score' => 0, 'max_score' => 59.99, 'grade_point' => 0],
            ],
        ])->assertOk()->assertJsonPath('name', $scale['name']);
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

        $this->postJson('/api/academic/results/department-submit', ['ids' => [$grade->id]])
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

        $this->postJson('/api/academic/results/department-submit', ['ids' => [$grade->id]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertEquals(GradeStatus::DEPARTMENT_SUBMITTED, $grade->fresh()->status);

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
