<?php

namespace Tests\Feature;

use App\Mail\ApplicationCredentialsMail;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\LegacyInvoiceImport;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\InvoiceImportColumns;
use App\Support\PermissionCatalog;
use App\Support\RegistrationCriteria;
use App\Support\StudentImportColumns;
use App\Support\TuitionProgress;
use App\Support\WalletImportColumns;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StudentImportTest extends TestCase
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
    }

    public function test_staff_can_download_template_with_lookup_sheets(): void
    {
        Sanctum::actingAs($this->staffUser(['students.import'], ['import-students']));

        $response = $this->get('/api/students/import-template?entry_mode=utme')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $spreadsheet = $this->loadWorkbook($response->streamedContent());
        foreach (['Instructions', 'Students', 'Campuses', 'Colleges', 'Departments', 'Programmes', 'Levels'] as $title) {
            $this->assertNotNull($spreadsheet->getSheetByName($title), "Missing sheet {$title}");
        }
        $this->assertNull($spreadsheet->getSheetByName('O-level subjects'));

        $programmes = $spreadsheet->getSheetByName('Programmes')->toArray(null, true, true, false);
        $codes = collect($programmes)->skip(1)->map(fn ($row) => (string) ($row[1] ?? ''));
        $this->assertTrue($codes->contains('BSC-CS'));
    }

    public function test_invoice_import_holds_rows_until_student_exists(): void
    {
        Sanctum::actingAs($this->staffUser(['finance.invoices.manage'], ['import-invoices']));

        $this->post('/api/invoices/import', [
            'file' => $this->spreadsheet('Invoices', InvoiceImportColumns::all(), InvoiceImportColumns::sample()),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.posted', 0)
            ->assertJsonPath('data.pending_by_matric.0.matric_number', 'BUT/2019/M/0001')
            ->assertJsonPath('data.pending_by_matric.0.rows', 1);

        $this->getJson('/api/invoices/import-pending')
            ->assertOk()
            ->assertJsonPath('pending_by_matric.0.matric_number', 'BUT/2019/M/0001')
            ->assertJsonPath('pending_by_matric.0.rows', 1);

        $this->assertSame(1, LegacyInvoiceImport::query()->where('status', 'pending')->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_student_import_uses_supplied_matric_posts_staged_finance_and_allows_login(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser(
            ['finance.invoices.manage', 'students.import'],
            ['import-invoices', 'import-wallet', 'import-students'],
        ));

        $this->post('/api/invoices/import', [
            'file' => $this->spreadsheet('Invoices', InvoiceImportColumns::all(), InvoiceImportColumns::sample()),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/wallet/import', [
            'file' => $this->spreadsheet('Wallet', WalletImportColumns::all(), WalletImportColumns::sample()),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.pending', 1);

        $this->post('/api/students/import', [
            'file' => $this->spreadsheet('Students', StudentImportColumns::all(), array_merge(StudentImportColumns::sample(), [
                'programme_id' => (string) $this->program->id,
                'email' => 'ada.student@example.com',
                'password' => 'OldPortal1!',
            ])),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'verify_nin' => '0',
            'send_credentials' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.invoices_posted', 1)
            ->assertJsonPath('data.wallet_posted', 1);

        $student = Student::query()->where('matric_number', 'BUT/2019/M/0001')->first();
        $this->assertNotNull($student);
        $this->assertSame(200, (int) $student->current_level);
        $this->assertSame('matriculated', $student->application->stage);
        $this->assertTrue($student->user->roles()->where('slug', 'student')->exists());

        $invoice = Invoice::query()->where('student_id', $student->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('tuition', $invoice->category);
        $this->assertSame('paid', $invoice->status);
        $this->assertTrue(TuitionProgress::meetsMinimum($student));
        $this->assertTrue(RegistrationCriteria::studentsQuery()->whereKey($student->id)->exists());
        $this->assertEqualsWithDelta(50000, (float) $student->wallet->fresh()->balance, 0.01);

        Mail::assertSent(ApplicationCredentialsMail::class, function (ApplicationCredentialsMail $mail) {
            [$label, $value] = $mail->signInIdentity();

            return $mail->loginId === 'BUT/2019/M/0001'
                && $label === 'Matric number'
                && $value === 'BUT/2019/M/0001';
        });

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'BUT/2019/M/0001',
            'password' => 'OldPortal1!',
        ])->assertOk();
    }

    public function test_wallet_debit_that_would_go_negative_skips_remaining_rows_for_that_matric(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser(
            ['finance.invoices.manage', 'students.import'],
            ['import-invoices', 'import-wallet', 'import-students'],
        ));

        $this->post('/api/students/import', [
            'file' => $this->spreadsheet('Students', StudentImportColumns::all(), array_merge(StudentImportColumns::sample(), [
                'programme_id' => (string) $this->program->id,
                'email' => 'wallet.student@example.com',
                'nin' => '12345678911',
                'password' => 'OldPortal1!',
            ])),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 1);

        $credit = WalletImportColumns::sample();
        $debitOk = array_merge(WalletImportColumns::sample(), [
            'type' => 'debit',
            'amount' => '20000',
            'occurred_at' => '2023-09-02 10:00:00',
            'reference' => 'WLT-LEG-0002',
            'description' => 'Partial debit',
        ]);
        $debitFail = array_merge(WalletImportColumns::sample(), [
            'type' => 'debit',
            'amount' => '40000',
            'occurred_at' => '2023-09-03 10:00:00',
            'reference' => 'WLT-LEG-0003',
            'description' => 'Too large',
        ]);
        $later = array_merge(WalletImportColumns::sample(), [
            'type' => 'credit',
            'amount' => '10',
            'occurred_at' => '2023-09-04 10:00:00',
            'reference' => 'WLT-LEG-0004',
            'description' => 'Should not post',
        ]);

        $this->post('/api/wallet/import', [
            'file' => $this->spreadsheetRows('Wallet', WalletImportColumns::all(), [$credit, $debitOk, $debitFail, $later]),
        ], ['Accept' => 'application/json'])->assertOk();

        $student = Student::query()->where('matric_number', 'BUT/2019/M/0001')->first();
        $this->assertEqualsWithDelta(30000, (float) $student->wallet->fresh()->balance, 0.01);
        $this->assertSame(2, $student->wallet->transactions()->count());
    }

    public function test_invoice_import_holds_application_fee_until_student_matched_by_application_number(): void
    {
        Sanctum::actingAs($this->staffUser(
            ['finance.invoices.manage', 'students.import'],
            ['import-invoices', 'import-students'],
        ));

        $this->post('/api/invoices/import', [
            'file' => $this->spreadsheet('Invoices', InvoiceImportColumns::all(), $this->applicationFeeInvoiceRow([
                'application_number' => 'APP/2019/0001',
                'payment_reference' => 'LEG-APP-0001',
            ])),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.posted', 0);

        $this->assertSame(1, LegacyInvoiceImport::query()->where('status', 'pending')->count());
        $this->assertSame(0, Invoice::query()->count());

        Mail::fake();
        $this->post('/api/students/import', [
            'file' => $this->spreadsheet('Students', StudentImportColumns::all(), array_merge(StudentImportColumns::sample(), [
                'programme_id' => (string) $this->program->id,
                'email' => 'legacy.appfee@example.com',
                'nin' => '12345678931',
                'jamb_registration' => '87654321CD',
                'old_application_number' => 'APP/2019/0001',
                'matric_number' => 'BUT/2019/M/0091',
                'password' => 'OldPortal1!',
            ])),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.invoices_posted', 1);

        $student = Student::query()->where('matric_number', 'BUT/2019/M/0091')->first();
        $this->assertNotNull($student);
        $application = $student->application;
        $this->assertSame('APP/2019/0001', $application->application_number);
        $this->assertNotNull($application->application_fee_invoice_id);

        $invoice = Invoice::query()->find($application->application_fee_invoice_id);
        $this->assertNotNull($invoice);
        $this->assertSame('application_fee', $invoice->category);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($student->id, $invoice->student_id);
        $this->assertSame($application->id, $invoice->application_id);
        $this->assertFalse($student->user->portalAccess()['unpaid_application_fee']);
    }

    public function test_invoice_import_holds_application_fee_until_student_matched_by_jamb(): void
    {
        Sanctum::actingAs($this->staffUser(
            ['finance.invoices.manage', 'students.import'],
            ['import-invoices', 'import-students'],
        ));

        $this->post('/api/invoices/import', [
            'file' => $this->spreadsheet('Invoices', InvoiceImportColumns::all(), $this->applicationFeeInvoiceRow([
                'jamb_registration' => '11223344EF',
                'payment_reference' => 'LEG-APP-JAMB-1',
            ])),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.pending', 1);

        Mail::fake();
        $this->post('/api/students/import', [
            'file' => $this->spreadsheet('Students', StudentImportColumns::all(), array_merge(StudentImportColumns::sample(), [
                'programme_id' => (string) $this->program->id,
                'email' => 'legacy.jambfee@example.com',
                'nin' => '12345678932',
                'jamb_registration' => '11223344EF',
                'matric_number' => 'BUT/2019/M/0092',
                'password' => 'OldPortal1!',
            ])),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.invoices_posted', 1);

        $student = Student::query()->where('matric_number', 'BUT/2019/M/0092')->first();
        $invoice = Invoice::query()->where('student_id', $student->id)->where('category', 'application_fee')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($invoice->id, $student->application->application_fee_invoice_id);
    }

    public function test_duplicate_email_is_skipped(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staffUser(['students.import'], ['import-students']));
        $payload = array_merge(StudentImportColumns::sample(), [
            'programme_id' => (string) $this->program->id,
            'email' => 'dup.student@example.com',
            'nin' => '12345678921',
        ]);

        $this->post('/api/students/import', [
            'file' => $this->spreadsheet('Students', StudentImportColumns::all(), $payload),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 1);

        $this->post('/api/students/import', [
            'file' => $this->spreadsheet('Students', StudentImportColumns::all(), array_merge($payload, [
                'nin' => '12345678922',
                'matric_number' => 'BUT/2019/M/0002',
            ])),
            'intake_id' => $this->intake->id,
            'entry_mode' => 'utme',
            'send_credentials' => '0',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, User::query()->where('email', 'dup.student@example.com')->count());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function applicationFeeInvoiceRow(array $overrides = []): array
    {
        $row = array_fill_keys(InvoiceImportColumns::all(), '');
        $row['category'] = 'application_fee';
        $row['amount'] = '5000';
        $row['full_amount'] = '5000';
        $row['description'] = 'Application fee';
        $row['paid_amount'] = '5000';
        $row['payment_date'] = '2019-08-01';
        $row['payment_method'] = 'legacy_import';
        $row['payment_reference'] = 'LEG-APP-0001';

        return array_merge($row, $overrides);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $row
     */
    private function spreadsheet(string $title, array $columns, array $row): UploadedFile
    {
        return $this->spreadsheetRows($title, $columns, [$row]);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string>>  $rows
     */
    private function spreadsheetRows(string $title, array $columns, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($columns, null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? '';
            }
            $sheet->fromArray([$values], null, 'A'.$line);
            $line++;
        }
        $path = sys_get_temp_dir().'/legacy-import-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, strtolower($title).'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function loadWorkbook(string $binary): Spreadsheet
    {
        $path = sys_get_temp_dir().'/student-template-'.uniqid().'.xlsx';
        file_put_contents($path, $binary);

        return IOFactory::load($path);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $navKeys
     */
    private function staffUser(array $permissions, array $navKeys): User
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
            'name' => 'Registry office '.$role->slug,
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
