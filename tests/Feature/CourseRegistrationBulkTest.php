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
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Support\GradeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseRegistrationBulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_registers_selected_courses_in_one_request(): void
    {
        [$user, $gst, $chm] = $this->openStudentWithTwoOfferings();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/my-registration', [
            'course_offering_ids' => [$gst->id, $chm->id],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'enrollments');

        $this->assertSame(2, Enrollment::query()->where('status', 'enrolled')->count());
        $this->assertTrue(Enrollment::query()->where('course_offering_id', $gst->id)->where('status', 'enrolled')->exists());
        $this->assertTrue(Enrollment::query()->where('course_offering_id', $chm->id)->where('status', 'enrolled')->exists());
    }

    public function test_student_can_print_registered_courses(): void
    {
        [$user, $gst, $chm] = $this->openStudentWithTwoOfferings();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/my-registration', [
            'course_offering_ids' => [$gst->id, $chm->id],
        ])->assertOk();

        $html = $this->get('/api/academic/my-registration/print')->assertOk()->getContent();
        $this->assertStringContainsString('Course registration form', $html);
        $this->assertStringContainsString('GST111', $html);
        $this->assertStringContainsString('CHM101', $html);
        $this->assertStringContainsString('ADA OKOYE', $html);
        $this->assertStringContainsString('Unit total:</strong> 4', $html);
        $this->assertStringNotContainsString('>Note<', $html);
        $this->assertStringNotContainsString('Faculty ', $html);
        $this->assertStringNotContainsString('Departmental ', $html);
        $this->assertStringNotContainsString('Overall ', $html);
    }

    public function test_student_can_print_a_previous_semester_registration(): void
    {
        [$user, $gst, $chm, $student] = $this->openStudentWithTwoOfferings();
        $current = AcademicTerm::query()->where('is_current', true)->firstOrFail();
        $current->update(['name' => 'Second']);
        $firstSemester = AcademicTerm::query()->create([
            'academic_session_id' => $current->academic_session_id,
            'name' => 'First',
            'session_label' => $current->session_label,
            'is_current' => false,
        ]);
        $firstOffering = CourseOffering::query()->create([
            'course_id' => $gst->course_id,
            'academic_term_id' => $firstSemester->id,
            'section' => 'A',
        ]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'course_offering_id' => $firstOffering->id,
            'status' => 'enrolled',
            'registered_at' => now()->subMonths(4),
        ]);
        Sanctum::actingAs($user);
        $this->postJson('/api/academic/my-registration', [
            'course_offering_ids' => [$chm->id],
        ])->assertOk();

        $terms = $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonCount(2, 'print_terms')
            ->json('print_terms');
        $this->assertTrue(collect($terms)->contains(fn ($term) => (int) $term['id'] === (int) $firstSemester->id));

        $firstHtml = $this->get('/api/academic/my-registration/print?academic_term_id='.$firstSemester->id)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('GST111', $firstHtml);
        $this->assertStringNotContainsString('CHM101', $firstHtml);
        $this->assertStringContainsString('First', $firstHtml);

        $currentHtml = $this->get('/api/academic/my-registration/print?academic_term_id='.$current->id)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('CHM101', $currentHtml);
        $this->assertStringNotContainsString('GST111', $currentHtml);
        $this->assertStringContainsString('Second', $currentHtml);
    }

    public function test_single_course_offering_id_still_registers(): void
    {
        [$user, $gst] = $this->openStudentWithTwoOfferings();
        Sanctum::actingAs($user);

        $this->postJson('/api/academic/my-registration', [
            'course_offering_id' => $gst->id,
        ])->assertSuccessful();

        $this->assertTrue(Enrollment::query()->where('course_offering_id', $gst->id)->where('status', 'enrolled')->exists());
    }

    public function test_opening_registration_does_not_enrol_available_courses(): void
    {
        [$user] = $this->openStudentWithTwoOfferings();
        Sanctum::actingAs($user);

        $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonCount(0, 'enrollments')
            ->assertJsonCount(2, 'available');

        $this->assertSame(0, Enrollment::query()->where('status', 'enrolled')->count());
    }

    public function test_same_session_fail_is_not_a_carry_over(): void
    {
        [$user, $gst, , $student] = $this->openStudentWithTwoOfferings();
        $current = AcademicTerm::query()->where('is_current', true)->firstOrFail();
        $firstSemester = AcademicTerm::query()->create([
            'academic_session_id' => $current->academic_session_id,
            'name' => 'First',
            'session_label' => $current->session_label,
            'is_current' => false,
        ]);
        $this->failCourseInTerm($student, $gst->course_id, $firstSemester);

        Sanctum::actingAs($user);

        $available = $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonCount(0, 'enrollments')
            ->json('available');

        $this->assertFalse(
            Enrollment::query()->where('course_offering_id', $gst->id)->where('status', 'enrolled')->exists()
        );
        $gstRow = collect($available)->firstWhere('id', $gst->id);
        $this->assertNotNull($gstRow);
        $this->assertFalse($gstRow['is_carry_over']);
        $this->assertFalse($gstRow['required']);
    }

    public function test_closed_previous_session_fail_is_auto_registered_as_carry_over(): void
    {
        [$user, $gst, , $student] = $this->openStudentWithTwoOfferings();
        $previousSession = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
            'closed_at' => now()->subDay(),
        ]);
        $previousTerm = AcademicTerm::query()->create([
            'academic_session_id' => $previousSession->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => false,
        ]);
        $this->failCourseInTerm($student, $gst->course_id, $previousTerm);

        Sanctum::actingAs($user);

        $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('enrollments.0.is_carry_over', true)
            ->assertJsonPath('enrollments.0.offering.course.code', 'GST111');

        $this->assertTrue(
            Enrollment::query()
                ->where('course_offering_id', $gst->id)
                ->where('status', 'enrolled')
                ->where('is_carry_over', true)
                ->exists()
        );
    }

    public function test_same_session_enrolment_is_not_locked_as_carry_over(): void
    {
        [$user, $gst, , $student] = $this->openStudentWithTwoOfferings();
        $current = AcademicTerm::query()->where('is_current', true)->firstOrFail();
        $firstSemester = AcademicTerm::query()->create([
            'academic_session_id' => $current->academic_session_id,
            'name' => 'First',
            'session_label' => $current->session_label,
            'is_current' => false,
        ]);
        $this->failCourseInTerm($student, $gst->course_id, $firstSemester);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'course_offering_id' => $gst->id,
            'status' => 'enrolled',
            'registered_at' => now(),
            'is_carry_over' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('enrollments.0.is_carry_over', false);

        $this->assertFalse(
            Enrollment::query()->where('course_offering_id', $gst->id)->value('is_carry_over')
        );
    }

    /**
     * @return array{0: User, 1: CourseOffering, 2: CourseOffering, 3: Student}
     */
    private function openStudentWithTwoOfferings(): array
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Chemistry']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Chemistry',
            'code' => 'BSC-CHE',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $gst = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'GST111',
            'title' => 'Communication in English',
            'units' => 2,
            'course_type' => 'general',
            'status' => 'core',
        ]);
        $chm = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'CHM101',
            'title' => 'General Chemistry I',
            'units' => 2,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $program->courses()->sync([$gst->id, $chm->id]);
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
            'normal_registration_closes_at' => now()->addDays(10),
            'late_registration_closes_at' => now()->addDays(20),
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        Invoice::query()->create([
            'number' => 'INV-BULK-TUIT',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'installment_percent' => 100,
        ]);
        $gstOffering = CourseOffering::query()->create([
            'course_id' => $gst->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $chmOffering = CourseOffering::query()->create([
            'course_id' => $chm->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);

        return [$user, $gstOffering, $chmOffering, $student];
    }

    private function failCourseInTerm(Student $student, int $courseId, AcademicTerm $term): void
    {
        $offering = CourseOffering::query()->create([
            'course_id' => $courseId,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'course_offering_id' => $offering->id,
            'status' => 'enrolled',
            'registered_at' => now()->subMonths(6),
        ]);
        Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'sitting' => 'main',
            'letter' => 'F',
            'points' => 0,
            'score' => 20,
            'status' => GradeStatus::RELEASED,
        ]);
    }
}
