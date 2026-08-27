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
use App\Models\UnitLimit;
use App\Models\User;
use App\Services\AcademicCalendarService;
use App\Services\SessionCloseService;
use App\Support\GradeStatus;
use Carbon\Carbon;
use Database\Seeders\GradingScaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnderloadCarryOverTest extends TestCase
{
    use RefreshDatabase;

    public function test_underloaded_semester_courses_fail_when_session_closes(): void
    {
        [$student, $session, $term, $offering] = $this->studentWithUnderload();

        app(SessionCloseService::class)->close($session, 'manual');

        $grade = Grade::query()->where('enrollment_id', Enrollment::query()
            ->where('student_id', $student->id)
            ->where('course_offering_id', $offering->id)
            ->value('id'))->first();

        $this->assertNotNull($grade);
        $this->assertSame('F', $grade->letter);
        $this->assertSame(GradeStatus::RELEASED, $grade->status);
        $this->assertSame('unit_requirement', $grade->source);
        $this->assertNotNull($session->fresh()->closed_at);
        $this->assertFalse($term->fresh()->is_current);
    }

    public function test_underloaded_courses_carry_over_after_session_closes(): void
    {
        [$student, $session, $term, $offering] = $this->studentWithUnderload();
        app(SessionCloseService::class)->close($session, 'manual');

        $next = AcademicSession::query()->create([
            'label' => '2027/2028',
            'starts_on' => '2027-10-01',
            'ends_on' => '2028-09-30',
        ]);
        $nextTerm = AcademicTerm::query()->create([
            'academic_session_id' => $next->id,
            'name' => 'First',
            'session_label' => '2027/2028',
            'is_current' => true,
            'normal_registration_closes_at' => now()->addDays(10),
            'late_registration_closes_at' => now()->addDays(20),
        ]);
        $nextOffering = CourseOffering::query()->create([
            'course_id' => $offering->course_id,
            'academic_term_id' => $nextTerm->id,
            'section' => 'A',
        ]);

        Sanctum::actingAs($student->user);
        $this->getJson('/api/academic/my-registration')
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('enrollments.0.is_carry_over', true)
            ->assertJsonPath('enrollments.0.offering.course.code', 'GST111');

        $this->assertTrue(
            Enrollment::query()
                ->where('course_offering_id', $nextOffering->id)
                ->where('is_carry_over', true)
                ->where('status', 'enrolled')
                ->exists()
        );
    }

    public function test_meeting_unit_minimum_does_not_fail_courses(): void
    {
        [$student, $session, $term, $offering] = $this->studentWithUnderload(units: 12, min: 10);
        app(SessionCloseService::class)->close($session, 'manual');

        $this->assertSame(0, Grade::query()->count());
        $this->assertTrue(
            Enrollment::query()
                ->where('student_id', $student->id)
                ->where('course_offering_id', $offering->id)
                ->where('status', 'enrolled')
                ->exists()
        );
        $this->assertTrue($term->exists);
    }

    public function test_released_exam_grade_is_not_overwritten_by_underload(): void
    {
        [$student, $session, , $offering] = $this->studentWithUnderload();
        $enrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('course_offering_id', $offering->id)
            ->firstOrFail();
        Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'sitting' => 'main',
            'letter' => 'B',
            'points' => 4,
            'score' => 62,
            'status' => GradeStatus::RELEASED,
            'source' => 'manual',
        ]);

        app(SessionCloseService::class)->close($session, 'manual');

        $this->assertSame('B', $enrollment->grades()->first()?->letter);
        $this->assertSame('manual', $enrollment->grades()->first()?->source);
    }

    public function test_semester_end_fails_underloaded_courses(): void
    {
        [, , $term, $offering] = $this->studentWithUnderload();
        $term->update([
            'auto_schedule' => true,
            'starts_on' => now()->subDays(40)->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        app(AcademicCalendarService::class)->sync(Carbon::today());

        $this->assertFalse($term->fresh()->is_current);
        $grade = Grade::query()->whereHas('enrollment', fn ($query) => $query->where('course_offering_id', $offering->id))->first();
        $this->assertNotNull($grade);
        $this->assertSame('F', $grade->letter);
        $this->assertSame('unit_requirement', $grade->source);
    }

    /**
     * @return array{0: Student, 1: AcademicSession, 2: AcademicTerm, 3: CourseOffering}
     */
    private function studentWithUnderload(int $units = 2, int $min = 10): array
    {
        $this->seed(GradingScaleSeeder::class);

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
        $course = Course::query()->create([
            'department_id' => $department->id,
            'code' => 'GST111',
            'title' => 'Communication in English',
            'units' => $units,
            'course_type' => 'general',
            'status' => 'core',
        ]);
        $program->courses()->sync([$course->id]);
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
            'normal_registration_closes_at' => now()->subDays(5),
            'late_registration_closes_at' => now()->subDay(),
        ]);
        UnitLimit::query()->create([
            'program_id' => $program->id,
            'academic_term_id' => $term->id,
            'bucket' => 'overall',
            'min_units' => $min,
            'max_units' => 24,
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
            'number' => 'INV-UNDERLOAD',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'installment_percent' => 100,
        ]);
        $offering = CourseOffering::query()->create([
            'course_id' => $course->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'course_offering_id' => $offering->id,
            'status' => 'enrolled',
            'registered_at' => now()->subDays(20),
        ]);

        return [$student, $session, $term, $offering];
    }
}
