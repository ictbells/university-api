<?php

namespace Tests\Feature;

use App\Mail\ReturningApplicationCredentialsMail;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\NinVerification;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicationAdmissionService;
use App\Support\PermissionCatalog;
use App\Support\Studentship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturningStudentApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_student_cannot_start_another_application_until_graduated(): void
    {
        [$user, $prior, $pgIntake] = $this->matriculatedStudentWithOpenPgIntake();
        $this->seedApplicantRole();
        Sanctum::actingAs($user);

        $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $pgIntake->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', Studentship::INCOMPLETE_PROGRAMME_MESSAGE);

        $this->assertSame(1, Application::query()->count());
        $this->assertSame($prior->id, Application::query()->value('id'));
    }

    public function test_student_can_start_a_new_application_that_is_prefilled_and_nin_biodata_stays_locked(): void
    {
        [$user, $prior, $pgIntake] = $this->matriculatedStudentWithOpenPgIntake();
        $this->seedApplicantRole();
        $this->graduate($user->student);

        Mail::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $pgIntake->id,
        ])->assertOk();

        $newId = (int) $response->json('id');
        $this->assertNotSame($prior->id, $newId);
        $this->assertSame('awaiting_application_fee', $response->json('stage'));

        $application = Application::query()->with('steps')->findOrFail($newId);
        $biodata = $application->steps->firstWhere('step_key', 'biodata')?->payload ?? [];
        $this->assertTrue($biodata['nin_locked'] ?? false);
        $this->assertSame('12345678901', $biodata['nin'] ?? null);
        $this->assertSame('Adaeze', $biodata['first_name'] ?? null);
        $this->assertSame('Okoye', $biodata['last_name'] ?? null);

        $personal = $application->steps->firstWhere('step_key', 'personal_details')?->payload ?? [];
        $this->assertSame('Single', $personal['marital_status'] ?? null);
        $this->assertSame('Christianity', $personal['religion'] ?? null);

        $kin = $application->steps->firstWhere('step_key', 'next_of_kin')?->payload ?? [];
        $this->assertSame('Chioma Okoye', $kin['next_of_kin'] ?? null);

        $this->assertTrue($user->fresh()->roles->contains('slug', 'applicant'));

        $application->update(['stage' => 'form_in_progress']);
        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'biodata',
            'payload' => [
                'nin' => '99999999999',
                'first_name' => 'Changed',
                'last_name' => 'Person',
                'date_of_birth' => '1990-01-01',
                'gender' => 'Male',
                'nin_locked' => false,
            ],
        ])->assertOk();

        $locked = $application->fresh(['steps'])->steps->firstWhere('step_key', 'biodata')?->payload ?? [];
        $this->assertSame('12345678901', $locked['nin'] ?? null);
        $this->assertSame('Adaeze', $locked['first_name'] ?? null);
        $this->assertSame('Okoye', $locked['last_name'] ?? null);
        $this->assertTrue($locked['nin_locked'] ?? false);
    }

    public function test_graduated_student_is_emailed_matric_and_a_new_password_to_continue(): void
    {
        [$user, $prior, $pgIntake] = $this->matriculatedStudentWithOpenPgIntake();
        $this->seedApplicantRole();
        $this->graduate($user->student);
        $user->update(['password' => 'OldSecret1!']);
        $matric = $user->student->matric_number;

        Mail::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/applications', [
            'entry_mode' => 'pg',
            'intake_id' => $pgIntake->id,
        ])->assertOk()
            ->assertJsonPath('credentials_emailed', true);

        $newNumber = $response->json('application_number');
        $this->assertNotSame($prior->application_number, $newNumber);
        $this->assertNotNull($response->json('credentials_emailed_at'));
        $this->assertFalse(Hash::check('OldSecret1!', $user->fresh()->password));

        $plain = null;
        Mail::assertSent(ReturningApplicationCredentialsMail::class, function (ReturningApplicationCredentialsMail $mail) use ($user, $prior, $newNumber, $matric, &$plain) {
            $plain = $mail->plainPassword;
            $mail->assertTo($user->email);
            $mail->assertHasSubject('Continue your Bells University application');
            $mail->assertSeeInHtml((string) $matric);
            $mail->assertSeeInHtml($prior->application_number);
            $mail->assertSeeInHtml($newNumber);
            $mail->assertSeeInHtml((string) $plain);
            $mail->assertSeeInHtml('Sign in with your matric number', false);
            $mail->assertSeeInHtml('You must update your records before you submit this application', false);

            return $mail->previousApplicationNumber === $prior->application_number
                && $mail->application->application_number === $newNumber
                && is_string($plain)
                && $plain !== '';
        });

        $this->assertNotNull($plain);
        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => $matric,
            'password' => $plain,
        ])->assertOk()
            ->assertJsonPath('can_apply_again', true);
    }

    public function test_acceptance_fee_reuses_the_existing_student_and_matric(): void
    {
        [$user, $prior] = $this->matriculatedStudentWithOpenPgIntake();
        $student = $user->student;
        $matric = $student->matric_number;
        $program = $this->programme('M.Sc Computing', 'postgraduate', ['pg']);

        $pgIntake = Intake::query()->where('entry_mode', 'pg')->firstOrFail();
        $new = Application::query()->create([
            'application_number' => 'APP/2026/PG001',
            'user_id' => $user->id,
            'intake_id' => $pgIntake->id,
            'program_id' => $program->id,
            'entry_mode' => 'pg',
            'stage' => 'awaiting_acceptance_fee',
        ]);
        foreach (Application::formSteps('pg') as $step) {
            $new->steps()->create(['step_key' => $step, 'status' => 'saved', 'payload' => []]);
        }

        $invoice = Invoice::query()->create([
            'number' => 'ACC-RETURN-1',
            'user_id' => $user->id,
            'application_id' => $new->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 0,
            'status' => 'paid',
        ]);

        app(ApplicationAdmissionService::class)->handleInvoicePaid($invoice);

        $this->assertSame(1, Student::query()->count());
        $this->assertSame($matric, $student->fresh()->matric_number);
        $this->assertNull($new->fresh()->student_id);
        $this->assertSame('acceptance_paid', $new->fresh()->stage);

        $staff = $this->staffUser(['admissions.view', 'admissions.clear']);
        Sanctum::actingAs($staff);
        $this->postJson("/api/applications/{$new->id}/clear")
            ->assertOk()
            ->assertJsonPath('data.stage', 'matriculated');

        $this->assertSame(1, Student::query()->count());
        $this->assertSame($matric, $student->fresh()->matric_number);
        $this->assertSame($student->id, $new->fresh()->student_id);
        $this->assertSame('matriculated', $new->fresh()->stage);
        $this->assertNotNull($new->fresh()->physically_cleared_at);
        $this->assertSame($prior->id, $student->fresh()->application_id);
        $this->assertSame($program->id, $student->fresh()->program_id);
        $this->assertSame('postgraduate', $student->fresh()->study_level);
        $this->assertDatabaseHas('student_programme_changes', [
            'student_id' => $student->id,
            'from_program_id' => $prior->program_id,
            'to_program_id' => $program->id,
            'from_level' => 100,
            'to_level' => 1,
            'kind' => 'subsequent_admission',
            'application_id' => $new->id,
        ]);
        $this->assertSame($prior->program_id, $prior->fresh()->program_id);
    }

    public function test_staff_resync_from_nin_refreshes_locked_biodata(): void
    {
        [$user] = $this->matriculatedStudentWithOpenPgIntake();
        $application = $user->applications()->first();
        $application->steps()->where('step_key', 'biodata')->update([
            'payload' => [
                'nin' => '12345678901',
                'nin_locked' => true,
                'first_name' => 'Old',
                'last_name' => 'Name',
                'date_of_birth' => '1999-01-01',
                'gender' => 'Female',
            ],
        ]);
        $user->student->update([
            'first_name' => 'Old',
            'last_name' => 'Name',
        ]);
        $verification = NinVerification::query()->where('user_id', $user->id)->firstOrFail();
        $verification->update([
            'prembly_reference' => 'ref-live-old',
            'mapped_fields' => [
                'first_name' => 'Old',
                'last_name' => 'Name',
                'date_of_birth' => '1999-01-01',
                'gender' => 'Female',
            ],
            'raw_snapshot' => ['firstname' => 'Old'],
            'verified_at' => now(),
        ]);

        config([
            'services.prembly.key' => 'test-key',
            'services.prembly.app_id' => 'test-app',
            'services.prembly.base' => 'https://api.prembly.com',
            'services.prembly.allow_demo' => false,
        ]);
        Http::fake([
            'https://api.prembly.com/identitypass/verification/vnin' => Http::response([
                'status' => true,
                'verification' => ['reference' => 'ref-live-new'],
                'nin_data' => [
                    'firstname' => 'Chinedu',
                    'middlename' => 'Ike',
                    'surname' => 'Okafor',
                    'birthdate' => '12-01-2001',
                    'gender' => 'm',
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/applications/{$application->id}/nin/resync")->assertForbidden();

        $staff = $this->staffUser(['admissions.view']);
        Sanctum::actingAs($staff);
        $this->postJson("/api/applications/{$application->id}/nin/resync")->assertForbidden();

        $resyncStaff = $this->staffUser(['admissions.view', 'identity.verify_nin']);
        Sanctum::actingAs($resyncStaff);
        $this->postJson("/api/applications/{$application->id}/nin/resync")
            ->assertOk()
            ->assertJsonPath('user.name', 'Chinedu Ike Okafor');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.prembly.com/identitypass/verification/vnin');

        $biodata = $application->fresh(['steps'])->steps->firstWhere('step_key', 'biodata')?->payload ?? [];
        $this->assertSame('Chinedu', $biodata['first_name'] ?? null);
        $this->assertSame('Okafor', $biodata['last_name'] ?? null);
        $this->assertSame('2001-01-12', $biodata['date_of_birth'] ?? null);
        $this->assertSame('Male', $biodata['gender'] ?? null);

        $student = $user->student->fresh();
        $this->assertSame('Chinedu', $student->first_name);
        $this->assertSame('Okafor', $student->last_name);
    }

    /**
     * @return array{0: User, 1: Application, 2: Intake}
     */
    private function matriculatedStudentWithOpenPgIntake(): array
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $ugIntake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $pgIntake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'PG 2025',
            'entry_mode' => 'pg',
            'is_open' => true,
            'application_fee_amount' => 15000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);

        $this->seedApplicantRole();
        $studentRole = Role::query()->firstOrCreate(
            ['slug' => 'student'],
            ['name' => 'Student', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create(['name' => 'Adaeze Chioma Okoye']);
        $user->roles()->attach($studentRole->id);

        $program = $this->programme('B.Sc Computer Science', 'undergraduate', ['utme']);
        $application = Application::query()->create([
            'application_number' => 'APP/2024/00001',
            'user_id' => $user->id,
            'intake_id' => $ugIntake->id,
            'academic_session_id' => $session->id,
            'program_id' => $program->id,
            'entry_mode' => 'utme',
            'stage' => 'matriculated',
        ]);
        foreach (Application::formSteps('utme') as $step) {
            $payload = [];
            $status = 'pending';
            if ($step === 'biodata') {
                $payload = [
                    'nin' => '12345678901',
                    'nin_locked' => true,
                    'first_name' => 'Adaeze',
                    'middle_name' => 'Chioma',
                    'last_name' => 'Okoye',
                    'date_of_birth' => '2004-03-18',
                    'gender' => 'Female',
                ];
                $status = 'saved';
            }
            if ($step === 'personal_details') {
                $payload = [
                    'marital_status' => 'Single',
                    'religion' => 'Christianity',
                    'country' => 'Nigeria',
                    'state' => 'Ogun',
                    'lga' => 'Ado-Odo/Ota',
                ];
                $status = 'saved';
            }
            if ($step === 'next_of_kin') {
                $payload = [
                    'next_of_kin' => 'Chioma Okoye',
                    'next_of_kin_relationship' => 'Mother',
                    'next_of_kin_phone' => '08031112222',
                    'next_of_kin_address' => 'Ota',
                ];
                $status = 'saved';
            }
            $application->steps()->create([
                'step_key' => $step,
                'status' => $status,
                'payload' => $payload,
            ]);
        }

        NinVerification::query()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'nin' => '12345678901',
            'mapped_fields' => [
                'first_name' => 'Adaeze',
                'middle_name' => 'Chioma',
                'last_name' => 'Okoye',
                'date_of_birth' => '2004-03-18',
                'gender' => 'Female',
            ],
            'raw_snapshot' => ['demo' => true],
            'verified_at' => now(),
        ]);

        $student = Student::query()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'program_id' => $program->id,
            'student_number' => 'BUT/2024/0001',
            'matric_number' => 'BUT/2024/M/0001',
            'first_name' => 'Adaeze',
            'middle_name' => 'Chioma',
            'last_name' => 'Okoye',
            'date_of_birth' => '2004-03-18',
            'gender' => 'Female',
            'marital_status' => 'Single',
            'religion' => 'Christianity',
            'country' => 'Nigeria',
            'state' => 'Ogun',
            'lga' => 'Ado-Odo/Ota',
            'nin' => '12345678901',
            'phone' => '08030000000',
            'address' => 'Ota',
            'next_of_kin' => 'Chioma Okoye',
            'next_of_kin_relationship' => 'Mother',
            'next_of_kin_phone' => '08031112222',
            'next_of_kin_address' => 'Ota',
            'study_level' => 'undergraduate',
            'current_level' => 100,
            'status' => 'active',
            'nin_locked' => true,
        ]);
        $application->update(['student_id' => $student->id]);

        return [$user->fresh(['roles', 'student']), $application->fresh(['steps']), $pgIntake];
    }

    /**
     * @param  list<string>  $entryModes
     */
    private function programme(string $name, string $studyLevel, array $entryModes): Program
    {
        $campus = Campus::query()->firstOrCreate(['name' => 'Main'], ['is_active' => true]);
        $faculty = Faculty::query()->firstOrCreate(
            ['name' => 'College of Natural Sciences'],
            ['campus_id' => $campus->id],
        );
        $department = Department::query()->firstOrCreate(
            ['name' => 'Computer Science'],
            ['faculty_id' => $faculty->id],
        );

        return Program::query()->create([
            'department_id' => $department->id,
            'name' => $name,
            'award_type' => $studyLevel === 'postgraduate' ? 'M.Sc' : 'B.Sc',
            'study_level' => $studyLevel,
            'entry_modes' => $entryModes,
            'duration_years' => $studyLevel === 'postgraduate' ? 2 : 4,
            'is_active' => true,
        ]);
    }

    private function seedApplicantRole(): void
    {
        Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
    }

    private function graduate(?Student $student): void
    {
        abort_unless($student, 500, 'Student record is required to graduate in this test.');
        $student->update([
            'status' => Studentship::STATUS_GRADUATED,
            'graduated_at' => now()->toDateString(),
            'studentship_expires_at' => now()->addYears(2)->toDateString(),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->firstOrCreate(
                ['key' => $perm['key']],
                ['module' => $perm['module'], 'label' => $perm['label']],
            );
        }
        $role = Role::query()->create([
            'name' => 'Test '.implode('-', $permissions),
            'slug' => 'test-'.substr(sha1(implode(',', $permissions).uniqid('', true)), 0, 12),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);
        $office = OfficeDepartment::query()->create([
            'name' => 'Test office '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
