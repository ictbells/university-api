<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Program;
use App\Models\Setting;
use App\Models\User;
use App\Services\MatricSequence;
use App\Services\StudentCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostgraduateMatricSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgraduate_uses_same_format_and_pg_matric_last_env_counter(): void
    {
        config([
            'sis.matric_last' => '2026/000100',
            'sis.pg_matric_last' => '2026/000020',
            'sis.matric_year' => '2026',
            'sis.pg_matric_year' => '2026',
            'sis.matric_digits' => 6,
        ]);

        $sequence = app(MatricSequence::class);

        $this->assertSame('2026/000101', $sequence->allocate($this->application('utme', 'undergraduate')));
        $this->assertSame('2026/000021', $sequence->allocate($this->application('pg', 'postgraduate')));
        $this->assertSame('2026/000022', $sequence->allocate($this->application('pg', 'postgraduate')));
        $this->assertSame('2026/000102', $sequence->allocate($this->application('utme', 'undergraduate')));

        $this->assertSame('2026/000102', Setting::getValue(MatricSequence::SETTING_KEY));
        $this->assertSame('2026/000022', Setting::getValue(MatricSequence::PG_SETTING_KEY));
    }

    public function test_student_creation_assigns_pg_matric_from_pg_sequence(): void
    {
        config([
            'sis.pg_matric_last' => '2026/000005',
            'sis.pg_matric_year' => '2026',
            'sis.matric_digits' => 6,
        ]);

        $application = $this->application('pg', 'postgraduate');
        $student = app(StudentCreationService::class)->createFromApplication($application);

        $this->assertSame('2026/000006', $student->matric_number);
        $this->assertSame('2026/000006', $student->student_number);
        $this->assertSame('2026/000006', Setting::getValue(MatricSequence::PG_SETTING_KEY));
    }

    private function application(string $entryMode, string $studyLevel): Application
    {
        $campus = Campus::query()->firstOrCreate(['name' => 'Main'], ['is_active' => true]);
        $faculty = Faculty::query()->firstOrCreate(
            ['name' => 'College '.$studyLevel],
            ['campus_id' => $campus->id, 'is_active' => true],
        );
        $department = Department::query()->firstOrCreate(
            ['name' => 'Dept '.$studyLevel, 'faculty_id' => $faculty->id],
            ['is_active' => true],
        );
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => strtoupper($entryMode).' Programme',
            'code' => strtoupper($entryMode).'-'.uniqid(),
            'award_type' => $entryMode === 'pg' ? 'M.Sc' : 'B.Sc',
            'study_level' => $studyLevel,
            'entry_modes' => [$entryMode],
            'duration_years' => $entryMode === 'pg' ? 2 : 4,
            'is_active' => true,
        ]);

        $session = AcademicSession::query()->firstOrCreate(['label' => '2026/2027']);
        $term = AcademicTerm::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'name' => 'First'],
            ['session_label' => '2026/2027', 'is_current' => true],
        );
        $intake = Intake::query()->firstOrCreate(
            ['entry_mode' => $entryMode],
            [
                'academic_term_id' => $term->id,
                'name' => strtoupper($entryMode).' 2026',
                'is_open' => true,
                'application_fee_amount' => 5000,
                'opens_on' => now()->subDay()->toDateString(),
                'closes_on' => now()->addMonth()->toDateString(),
            ],
        );

        $user = User::factory()->create(['status' => 'active']);

        return Application::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'intake_id' => $intake->id,
            'entry_mode' => $entryMode,
            'stage' => 'acceptance_paid',
            'application_number' => 'APP/2026/'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        ]);
    }
}
