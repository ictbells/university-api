<?php

namespace Tests\Feature;

use App\Mail\StudentMatricIssuedMail;
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
use Illuminate\Support\Facades\Mail;
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

    public function test_student_creation_emails_postgraduate_matric(): void
    {
        Mail::fake();
        config([
            'sis.pg_matric_last' => '2026/000005',
            'sis.pg_matric_year' => '2026',
            'sis.matric_digits' => 6,
        ]);

        $application = $this->application('pg', 'postgraduate');
        $student = app(StudentCreationService::class)->createFromApplication($application);

        Mail::assertQueued(StudentMatricIssuedMail::class, function (StudentMatricIssuedMail $mail) use ($student) {
            return $mail->hasTo($student->user->email)
                && $mail->matricNumber === '2026/000006'
                && $mail->isPostgraduate();
        });
    }

    public function test_command_can_email_only_postgraduate_matrics(): void
    {
        config([
            'sis.matric_last' => '2026/000100',
            'sis.pg_matric_last' => '2026/000020',
            'sis.matric_year' => '2026',
            'sis.pg_matric_year' => '2026',
            'sis.matric_digits' => 6,
        ]);
        $pg = app(StudentCreationService::class)->createFromApplication($this->application('pg', 'postgraduate'));
        app(StudentCreationService::class)->createFromApplication($this->application('utme', 'undergraduate'));
        Mail::fake();

        $this->artisan('students:email-matric', ['--entry-mode' => 'pg'])
            ->expectsOutput('Sent 1 matric email.')
            ->assertSuccessful();
        Mail::assertSent(StudentMatricIssuedMail::class, 1);
        Mail::assertSent(StudentMatricIssuedMail::class, function (StudentMatricIssuedMail $mail) use ($pg) {
            return $mail->hasTo($pg->user->email)
                && $mail->matricNumber === $pg->matric_number
                && $mail->isPostgraduate();
        });
    }

    public function test_undergraduate_entry_mode_uses_matric_last_not_pg_even_if_program_tagged_pg(): void
    {
        config([
            'sis.matric_last' => '2026/000200',
            'sis.pg_matric_last' => '2026/000050',
            'sis.matric_year' => '2026',
            'sis.pg_matric_year' => '2026',
            'sis.matric_digits' => 6,
        ]);

        $sequence = app(MatricSequence::class);
        // Application is UTME/DE but linked to a PG-only programme (data mismatch).
        $utme = $this->application('utme', 'postgraduate', ['pg']);
        $de = $this->application('de', 'postgraduate', ['pg']);

        $this->assertSame(MatricSequence::TRACK_UNDERGRADUATE, $sequence->trackFor($utme));
        $this->assertSame(MatricSequence::TRACK_UNDERGRADUATE, $sequence->trackFor($de));
        $this->assertSame('2026/000201', $sequence->allocate($utme));
        $this->assertSame('2026/000202', $sequence->allocate($de));
        $this->assertSame('2026/000202', Setting::getValue(MatricSequence::SETTING_KEY));
        $this->assertSame('2026/000050', (string) config('sis.pg_matric_last'));
    }

    /**
     * @param  list<string>|null  $entryModes
     */
    private function application(string $entryMode, string $studyLevel, ?array $entryModes = null): Application
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
            'entry_modes' => $entryModes ?? [$entryMode],
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
