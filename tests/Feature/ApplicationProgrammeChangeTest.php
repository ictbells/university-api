<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicationStaffUpdateService;
use App\Services\CourseRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationProgrammeChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_college_programme_change_keeps_the_student_level(): void
    {
        [$application, $student, $from, $to] = $this->registeredStudentOnProgramme(200);
        $this->assertSame($from->department->faculty_id, $to->department->faculty_id);

        $this->changeProgramme($application, $to->id);

        $this->assertSame(200, (int) $student->fresh()->current_level);
        $this->assertSame($to->id, (int) $student->fresh()->program_id);
        $this->assertSame($to->id, (int) $application->fresh()->program_id);
        $this->assertDatabaseHas('student_programme_changes', [
            'student_id' => $student->id,
            'from_program_id' => $from->id,
            'to_program_id' => $to->id,
            'from_level' => 200,
            'to_level' => 200,
            'same_college' => 1,
        ]);
    }

    public function test_different_college_programme_change_drops_one_level_band(): void
    {
        [$application, $student, , $otherCollege] = $this->registeredStudentOnProgramme(200, otherCollege: true);

        $this->changeProgramme($application, $otherCollege->id);

        $this->assertSame(100, (int) $student->fresh()->current_level);
        $this->assertSame($otherCollege->id, (int) $student->fresh()->program_id);
        $this->assertDatabaseHas('student_programme_changes', [
            'student_id' => $student->id,
            'to_program_id' => $otherCollege->id,
            'from_level' => 200,
            'to_level' => 100,
            'same_college' => 0,
        ]);
    }

    public function test_100l_programme_change_stays_100l_even_across_colleges(): void
    {
        [$application, $student, , $otherCollege] = $this->registeredStudentOnProgramme(100, otherCollege: true);

        $this->changeProgramme($application, $otherCollege->id);

        $this->assertSame(100, (int) $student->fresh()->current_level);
    }

    public function test_400l_students_cannot_change_programme(): void
    {
        [$application, $student, , $to] = $this->registeredStudentOnProgramme(400);

        try {
            $this->changeProgramme($application, $to->id);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('first_choice_program_id', $e->errors());
        }

        $this->assertSame(400, (int) $student->fresh()->current_level);
        $this->assertNotSame($to->id, (int) $student->fresh()->program_id);
    }

    public function test_same_college_change_makes_outstanding_new_programme_courses_registrable(): void
    {
        [$application, $student, $from, $to, $term] = $this->registeredStudentOnProgramme(200);
        [$level100, $level200, $level300] = $this->undergraduateLevels();
        $intro = Course::query()->create([
            'department_id' => $to->department_id,
            'code' => 'CYB101',
            'title' => 'Intro to Cybersecurity',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $current = Course::query()->create([
            'department_id' => $to->department_id,
            'code' => 'CYB201',
            'title' => 'Secure Programming',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $later = Course::query()->create([
            'department_id' => $to->department_id,
            'code' => 'CYB301',
            'title' => 'Network Defence',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $fromCourse = Course::query()->create([
            'department_id' => $from->department_id,
            'code' => 'CSC201',
            'title' => 'Old programme course',
            'units' => 3,
            'course_type' => 'departmental',
            'status' => 'core',
        ]);
        $from->courses()->sync([
            $fromCourse->id => ['academic_level_id' => $level200->id, 'bucket' => 'departmental'],
        ]);
        $to->courses()->sync([
            $intro->id => ['academic_level_id' => $level100->id, 'bucket' => 'departmental'],
            $current->id => ['academic_level_id' => $level200->id, 'bucket' => 'departmental'],
            $later->id => ['academic_level_id' => $level300->id, 'bucket' => 'departmental'],
        ]);
        $introOffering = CourseOffering::query()->create([
            'course_id' => $intro->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $currentOffering = CourseOffering::query()->create([
            'course_id' => $current->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $laterOffering = CourseOffering::query()->create([
            'course_id' => $later->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);
        $oldOffering = CourseOffering::query()->create([
            'course_id' => $fromCourse->id,
            'academic_term_id' => $term->id,
            'section' => 'A',
        ]);

        $this->changeProgramme($application, $to->id);
        $student = $student->fresh(['program', 'user']);
        $this->assertSame(200, (int) $student->current_level);

        $service = app(CourseRegistrationService::class);
        $available = $service->availableOfferings($student, $term);
        $ids = $available->pluck('id')->all();

        $this->assertContains($introOffering->id, $ids);
        $this->assertContains($currentOffering->id, $ids);
        $this->assertNotContains($laterOffering->id, $ids);
        $this->assertNotContains($oldOffering->id, $ids);
        $this->assertTrue((bool) $available->firstWhere('id', $introOffering->id)['is_outstanding']);
        $this->assertFalse((bool) $available->firstWhere('id', $currentOffering->id)['is_outstanding']);

        $enrollment = $service->register($student, $introOffering, $student->user, false);
        $this->assertSame('enrolled', $enrollment->status);
        $this->assertFalse((bool) $enrollment->is_carry_over);
    }

    /**
     * @return array{0: Application, 1: Student, 2: Program, 3: Program, 4: AcademicTerm}
     */
    private function registeredStudentOnProgramme(int $level, bool $otherCollege = false): array
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $college = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Computing']);
        $department = Department::query()->create(['faculty_id' => $college->id, 'name' => 'Computer Science']);
        $from = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $toDepartment = $otherCollege
            ? Department::query()->create([
                'faculty_id' => Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Engineering'])->id,
                'name' => 'Electrical Engineering',
            ])
            : Department::query()->create(['faculty_id' => $college->id, 'name' => 'Cybersecurity']);
        $to = Program::query()->create([
            'department_id' => $toDepartment->id,
            'name' => $otherCollege ? 'B.Eng Electrical' : 'B.Sc Cybersecurity',
            'code' => $otherCollege ? 'BENG-EEE' : 'BSC-CYB',
            'award_type' => $otherCollege ? 'B.Eng' : 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
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
            'normal_registration_closes_at' => now()->addDays(10),
            'late_registration_closes_at' => now()->addDays(20),
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $user = User::factory()->create([
            'status' => 'active',
            'email' => 'ada.okoye@example.com',
            'jamb_registration' => '12345678AB',
        ]);
        $application = Application::query()->create([
            'application_number' => 'APP/2026/COP01',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'program_id' => $from->id,
            'entry_mode' => 'utme',
            'stage' => 'matriculated',
            'jamb_registration' => '12345678AB',
        ]);
        foreach (Application::formSteps('utme') as $step) {
            $application->steps()->create([
                'step_key' => $step,
                'status' => 'saved',
                'payload' => $step === 'programme_selection'
                    ? ['first_choice_program_id' => $from->id]
                    : [],
            ]);
        }
        $student = Student::query()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'program_id' => $from->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'current_level' => $level,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);
        $application->update(['student_id' => $student->id]);
        Invoice::query()->create([
            'number' => 'INV-COP-TUIT',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'installment_percent' => 100,
            'academic_session_id' => $session->id,
        ]);

        return [$application->fresh(['user', 'steps', 'student']), $student, $from->load('department'), $to->load('department'), $term];
    }

    /**
     * @return array{0: AcademicLevel, 1: AcademicLevel, 2: AcademicLevel}
     */
    private function undergraduateLevels(): array
    {
        return [
            AcademicLevel::query()->create([
                'name' => '100 Level',
                'code' => '100',
                'study_level' => 'undergraduate',
                'sort_order' => 1,
                'is_active' => true,
            ]),
            AcademicLevel::query()->create([
                'name' => '200 Level',
                'code' => '200',
                'study_level' => 'undergraduate',
                'sort_order' => 2,
                'is_active' => true,
            ]),
            AcademicLevel::query()->create([
                'name' => '300 Level',
                'code' => '300',
                'study_level' => 'undergraduate',
                'sort_order' => 3,
                'is_active' => true,
            ]),
        ];
    }

    private function changeProgramme(Application $application, int $programId): Application
    {
        Sanctum::actingAs($application->user);

        return app(ApplicationStaffUpdateService::class)->update($application, [
            'email' => $application->user->email,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'jamb_registration' => $application->jamb_registration ?: $application->user->jamb_registration,
            'first_choice_program_id' => $programId,
        ]);
    }
}
