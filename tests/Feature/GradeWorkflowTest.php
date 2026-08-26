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
        $role = Role::query()->create(['name' => 'Exam Officer', 'slug' => 'exam-officer']);
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
            ->assertJsonPath('layout', 'department_matrix')
            ->assertJsonPath('students.0.matric_number', 'BU/2020/001');

        $this->assertNotNull($grade->id);
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
}
