<?php

namespace Tests\Feature;

use App\Jobs\ImportApplicantsJob;
use App\Mail\ApplicationCredentialsMail;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\OfficeDepartment;
use App\Models\OlevelSubject;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\PremblyService;
use App\Support\ApplicantImportColumns;
use App\Support\InvoiceImportColumns;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ApplicantImportTest extends TestCase
{
    use RefreshDatabase;

    private Intake $intake;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
            'normal_registration_closes_at' => now()->addDays(10),
            'late_registration_closes_at' => now()->addDays(20),
        ]);
        $this->program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::UG_STANDARD),
        ]);
        $this->intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
        ]);
        OlevelSubject::query()->create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);
        OlevelSubject::query()->create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);
    }

    public function test_staff_can_download_utme_template(): void
    {
        Sanctum::actingAs($this->staffUser());

        $response = $this->get('/api/applicants/import-template?entry_mode=utme')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $spreadsheet = $this->loadWorkbook($response->streamedContent());
        foreach (['Instructions', 'Applicants', 'Campuses', 'Colleges', 'Departments', 'Programmes', 'Levels', 'O-level subjects'] as $title) {
            $this->assertNotNull($spreadsheet->getSheetByName($title), "Missing sheet {$title}");
        }

        $programmes = $spreadsheet->getSheetByName('Programmes')->toArray(null, true, true, false);
        $codes = collect($programmes)->skip(1)->map(fn ($row) => (string) ($row[1] ?? ''));
        $this->assertTrue($codes->contains('BSC-CS'));

        $subjects = $spreadsheet->getSheetByName('O-level subjects')->toArray(null, true, true, false);
        $names = collect($subjects)->skip(1)->map(fn ($row) => (string) ($row[2] ?? ''));
        $this->assertTrue($names->contains('English Language'));
    }

    public function test_import_creates_unsubmitted_application_and_allows_student_login(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser());
        $file = $this->spreadsheet([
            'email' => 'ada.import@example.com',
            'phone' => '08030000001',
            'nin' => '12345678901',
            'jamb_registration' => '12345678AB',
        ]);

        $this->post('/api/applicants/import', [
            'file' => $file,
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'verify_nin' => '0',
            'send_credentials' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1);

        $application = Application::query()->where('jamb_registration', '12345678AB')->first();
        $this->assertNotNull($application);
        $this->assertSame('form_in_progress', $application->stage);
        $this->assertSame('required_documents', $application->current_step);
        $this->assertNull($application->submitted_at);
        $this->assertSame('pending', $application->steps()->where('step_key', 'required_documents')->value('status'));
        $this->assertSame(0, $application->documents()->count());
        $this->assertTrue($application->user->roles()->where('slug', 'applicant')->exists());

        $plain = null;
        Mail::assertSent(ApplicationCredentialsMail::class, function (ApplicationCredentialsMail $mail) use (&$plain) {
            $plain = $mail->plainPassword;

            return $mail->loginId === '12345678AB';
        });
        $this->assertNotNull($plain);

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => '12345678AB',
            'password' => $plain,
        ])->assertOk();

        $biodata = $application->steps()->where('step_key', 'biodata')->first();
        $payload = is_array($biodata?->payload) ? $biodata->payload : [];
        $payload['nin_locked'] = true;
        $biodata->update(['payload' => $payload]);

        Sanctum::actingAs($application->user);
        $this->postJson("/api/applications/{$application->id}/submit")
            ->assertStatus(422)
            ->assertJsonFragment(['missing' => ['required_documents']]);
        $this->assertSame('form_in_progress', $application->fresh()->stage);
        $this->assertNull($application->fresh()->submitted_at);
    }

    public function test_provided_password_is_kept_and_not_emailed_unless_forced(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser());
        $file = $this->spreadsheet([
            'email' => 'chidi.import@example.com',
            'phone' => '08030000002',
            'password' => 'OldPortal1!',
            'nin' => '12345678902',
            'jamb_registration' => '12345678CD',
        ]);

        $this->post('/api/applicants/import', [
            'file' => $file,
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'verify_nin' => '0',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.emailed', 0);

        Mail::assertNothingSent();
        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => '12345678CD',
            'password' => 'OldPortal1!',
        ])->assertOk();
    }

    public function test_nin_verification_failure_does_not_create_a_user(): void
    {
        Mail::fake();
        $this->mock(PremblyService::class, function ($mock) {
            $mock->shouldReceive('verify')->andThrow(
                ValidationException::withMessages(['nin' => 'NIN was rejected.']),
            );
        });
        Sanctum::actingAs($this->staffUser());
        $file = $this->spreadsheet([
            'email' => 'nin.fail@example.com',
            'phone' => '08030000003',
            'nin' => '12345678903',
            'jamb_registration' => '12345678EF',
        ]);

        $this->post('/api/applicants/import', [
            'file' => $file,
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'verify_nin' => '1',
            'send_credentials' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.nin_failed', 1);

        $this->assertDatabaseMissing('users', ['email' => 'nin.fail@example.com']);
    }

    public function test_applicant_import_posts_pending_application_fee_invoice_by_jamb(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser(['admissions.import', 'finance.invoices.manage'], ['import-applicants', 'import-invoices']));

        $this->post('/api/invoices/import', [
            'file' => $this->invoiceSpreadsheet([
                'jamb_registration' => '99887766AB',
                'category' => 'application_fee',
                'amount' => '5000',
                'full_amount' => '5000',
                'paid_amount' => '5000',
                'payment_date' => '2025-01-10',
                'payment_method' => 'legacy_import',
                'payment_reference' => 'LEG-APP-JAMB-2',
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.pending', 1);

        $this->post('/api/applicants/import', [
            'file' => $this->spreadsheet([
                'email' => 'app.fee.import@example.com',
                'phone' => '08030000011',
                'nin' => '12345678911',
                'jamb_registration' => '99887766AB',
            ]),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.invoices_posted', 1)
            ->assertJsonPath('data.application_fees_generated', 0);

        $application = Application::query()->where('jamb_registration', '99887766AB')->first();
        $this->assertNotNull($application);
        $this->assertNotNull($application->application_fee_invoice_id);
        $invoice = Invoice::query()->find($application->application_fee_invoice_id);
        $this->assertSame('application_fee', $invoice->category);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($application->id, $invoice->application_id);
        $this->assertFalse($application->user->portalAccess()['unpaid_application_fee']);
        $this->assertSame(1, Invoice::query()->where('category', 'application_fee')->where('application_id', $application->id)->count());
    }

    public function test_applicant_import_generates_unpaid_application_fee_when_no_pending_invoice(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser());

        $this->post('/api/applicants/import', [
            'file' => $this->spreadsheet([
                'email' => 'app.fee.generate@example.com',
                'phone' => '08030000012',
                'nin' => '12345678912',
                'jamb_registration' => '11223344CD',
            ]),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.invoices_posted', 0)
            ->assertJsonPath('data.application_fees_generated', 1);

        $application = Application::query()->where('jamb_registration', '11223344CD')->first();
        $this->assertNotNull($application);
        $this->assertNotNull($application->application_fee_invoice_id);
        $invoice = Invoice::query()->find($application->application_fee_invoice_id);
        $this->assertSame('application_fee', $invoice->category);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertEquals(5000.0, (float) $invoice->amount);
        $this->assertTrue($application->user->portalAccess()['unpaid_application_fee']);
    }

    public function test_applicant_import_fails_when_intake_missing_application_fee_amount(): void
    {
        Mail::fake();
        $this->intake->update(['application_fee_amount' => null]);
        Sanctum::actingAs($this->staffUser());

        $response = $this->post('/api/applicants/import', [
            'file' => $this->spreadsheet([
                'email' => 'app.fee.missing@example.com',
                'phone' => '08030000013',
                'nin' => '12345678913',
                'jamb_registration' => '55667788EF',
            ]),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 1);

        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('application fee', strtolower($errors[0]['message'] ?? ''));
        $this->assertDatabaseMissing('users', ['email' => 'app.fee.missing@example.com']);
    }

    public function test_duplicate_email_is_skipped(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser());
        $payload = [
            'email' => 'dup.import@example.com',
            'phone' => '08030000004',
            'nin' => '12345678904',
            'jamb_registration' => '12345678GH',
        ];

        $this->post('/api/applicants/import', [
            'file' => $this->spreadsheet($payload),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 1);

        $this->post('/api/applicants/import', [
            'file' => $this->spreadsheet([
                ...$payload,
                'nin' => '12345678905',
                'jamb_registration' => '12345678IJ',
            ]),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, User::query()->where('email', 'dup.import@example.com')->count());
    }

    public function test_nin_verify_is_queued_when_queue_is_not_sync(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();
        Sanctum::actingAs($this->staffUser());

        $this->post('/api/applicants/import', [
            'file' => $this->spreadsheet([
                'email' => 'queue.import@example.com',
                'phone' => '08030000006',
                'nin' => '12345678906',
                'jamb_registration' => '12345678KL',
            ]),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'verify_nin' => '1',
        ], ['Accept' => 'application/json'])->assertStatus(202)
            ->assertJsonPath('queued', true);

        Queue::assertPushed(ImportApplicantsJob::class);
    }

    private function loadWorkbook(string $binary): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = sys_get_temp_dir().'/applicant-template-'.uniqid().'.xlsx';
        file_put_contents($path, $binary);

        return IOFactory::load($path);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function spreadsheet(array $overrides): UploadedFile
    {
        $columns = ApplicantImportColumns::forMode('utme');
        $row = array_merge(ApplicantImportColumns::sample('utme'), [
            'first_choice_programme_id' => (string) $this->program->id,
        ], $overrides);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Applicants');
        $sheet->fromArray($columns, null, 'A1');
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? '';
        }
        $sheet->fromArray([$values], null, 'A2');
        $path = sys_get_temp_dir().'/applicant-import-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'applicants.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function invoiceSpreadsheet(array $row): UploadedFile
    {
        $columns = InvoiceImportColumns::all();
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? '';
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoices');
        $sheet->fromArray($columns, null, 'A1');
        $sheet->fromArray([$values], null, 'A2');
        $path = sys_get_temp_dir().'/invoice-import-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'invoices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $navKeys
     */
    private function staffUser(array $permissions = ['admissions.import'], array $navKeys = ['import-applicants']): User
    {
        $role = Role::query()->create([
            'name' => 'Importer',
            'slug' => 'importer-'.substr(sha1(uniqid()), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);
        $office = OfficeDepartment::query()->create([
            'name' => 'Admissions office '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys($navKeys);
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
