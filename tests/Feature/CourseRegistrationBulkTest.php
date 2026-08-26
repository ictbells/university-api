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
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
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

    /**
     * @return array{0: User, 1: CourseOffering, 2: CourseOffering}
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

        return [$user, $gstOffering, $chmOffering];
    }
}
