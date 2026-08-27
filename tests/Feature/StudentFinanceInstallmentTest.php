<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\InvoiceRebate;
use App\Models\OfficeDepartment;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\RebateType;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Support\PermissionCatalog;
use App\Support\TuitionProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentFinanceInstallmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_status_bills_full_tuition_and_keeps_remaining_balance(): void
    {
        $staff = $this->financeStaff();
        [$student, $tuition] = $this->studentWithPaidQuarterTuition();

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->json();

        $this->assertEquals(17000.0, $payload['summary']['billed']);
        $this->assertEquals(4250.0, $payload['summary']['paid']);
        $this->assertEquals(12750.0, $payload['summary']['outstanding']);
        $this->assertEquals('outstanding', $payload['summary']['clearance']);

        $invoiceRow = collect($payload['invoices'])->firstWhere('id', $tuition->id);
        $this->assertEquals(17000.0, $invoiceRow['amount']);
        $this->assertEquals(4250.0, $invoiceRow['installment_amount']);
        $this->assertEquals(4250.0, $invoiceRow['amount_paid']);
        $this->assertEquals(12750.0, $invoiceRow['balance']);
        $this->assertEquals('partial', $invoiceRow['status']);
        $this->assertEquals(25, $invoiceRow['installment_percent']);

        // Payable installment on the invoice document stays settled for wallet/Paystack.
        $this->assertEquals(0.0, (float) $tuition->fresh()->balance);
        $this->assertEquals('paid', $tuition->fresh()->status);

        $this->getJson('/api/finance/student-roster?student_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('data.0.billed', 17000)
            ->assertJsonPath('data.0.paid', 4250)
            ->assertJsonPath('data.0.outstanding', 12750)
            ->assertJsonPath('data.0.clearance', 'outstanding');
    }

    public function test_student_status_with_fees_and_partial_tuition(): void
    {
        $staff = $this->financeStaff();
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Chioma',
            'last_name' => 'Okafor',
            'matric_number' => 'BUT/2026/0002',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 5750]);

        $tuition = Invoice::query()->create([
            'number' => 'INV-UNDERPAID',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'installment_percent' => 25,
            'amount' => 4250,
            'full_amount' => 17000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $tuition->id,
            'method' => 'wallet',
            'amount' => 4250,
            'status' => 'successful',
            'reference' => 'WALLET-UNDERPAID',
            'receipt_no' => 'RCP-UND',
            'purpose' => 'tuition',
        ]);
        Invoice::query()->create([
            'number' => 'INV-ACCEPT',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'acceptance_fee',
            'amount' => 7000,
            'full_amount' => 7000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
        ])->payments()->create([
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 7000,
            'status' => 'successful',
            'reference' => 'RCP-ACC',
            'receipt_no' => 'RCP-ACC',
            'purpose' => 'acceptance_fee',
        ]);
        Invoice::query()->create([
            'number' => 'INV-APP',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'application_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
        ])->payments()->create([
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'successful',
            'reference' => 'RCP-APP',
            'receipt_no' => 'RCP-APP',
            'purpose' => 'application_fee',
        ]);

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->json();

        $this->assertEquals(29000.0, $payload['summary']['billed']);
        $this->assertEquals(16250.0, $payload['summary']['paid']);
        $this->assertEquals(12750.0, $payload['summary']['outstanding']);
        $this->assertEquals('outstanding', $payload['summary']['clearance']);

        $invoiceRow = collect($payload['invoices'])->firstWhere('id', $tuition->id);
        $this->assertEquals(17000.0, $invoiceRow['amount']);
        $this->assertEquals(4250.0, $invoiceRow['amount_paid']);
        $this->assertEquals(12750.0, $invoiceRow['balance']);
        $this->assertEquals('partial', $invoiceRow['status']);
    }

    public function test_billed_uses_programme_school_fees_when_invoice_omits_full_amount(): void
    {
        $staff = $this->financeStaff();
        $student = $this->studentOnProgrammeWithSchoolFees(40000);
        $user = $student->user;

        $tuition = Invoice::query()->create([
            'number' => 'INV-NO-FULL',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'installment_percent' => 25,
            'amount' => 10000,
            'full_amount' => null,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $tuition->id,
            'method' => 'wallet',
            'amount' => 10000,
            'status' => 'successful',
            'reference' => 'WALLET-NO-FULL',
            'receipt_no' => 'RCP-NO-FULL',
            'purpose' => 'tuition',
        ]);
        Invoice::query()->create([
            'number' => 'INV-HOSTEL-PENDING',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->json();

        $this->assertEquals(45000.0, $payload['summary']['billed']);
        $this->assertEquals(10000.0, $payload['summary']['paid']);
        $this->assertEquals(35000.0, $payload['summary']['outstanding']);
        $this->assertEquals('outstanding', $payload['summary']['clearance']);

        $this->getJson('/api/finance/student-roster?student_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('data.0.billed', 45000)
            ->assertJsonPath('data.0.clearance', 'outstanding');
    }

    public function test_cleared_only_after_one_hundred_percent_school_fees(): void
    {
        $staff = $this->financeStaff();
        [$student, $tuition] = $this->studentWithPaidQuarterTuition();
        $user = $student->user;

        Invoice::query()->create([
            'number' => 'INV-HOSTEL-OPEN',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 2000,
            'full_amount' => 2000,
            'balance' => 2000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('summary.clearance', 'outstanding');

        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $tuition->id,
            'method' => 'wallet',
            'amount' => 12750,
            'status' => 'successful',
            'reference' => 'WALLET-FULL',
            'receipt_no' => 'RCP-FULL',
            'purpose' => 'tuition',
        ]);
        $tuition->update(['installment_percent' => 100, 'amount' => 17000, 'balance' => 0, 'status' => 'paid']);

        $payload = $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->json();

        $this->assertEquals('cleared', $payload['summary']['clearance']);
        $this->assertEquals(19000.0, $payload['summary']['billed']);
        $this->assertEquals(2000.0, $payload['summary']['outstanding']);

        $this->getJson('/api/finance/student-roster?clearance=cleared&student_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('data.0.clearance', 'cleared');
    }

    public function test_tuition_progress_uses_amount_paid_against_full_year(): void
    {
        [, $tuition] = $this->studentWithPaidQuarterTuition();

        $this->assertEquals(25.0, TuitionProgress::invoicePercent($tuition->fresh()));

        $unpaid = $tuition->replicate(['number']);
        $unpaid->number = 'INV-UNPAID-25';
        $unpaid->balance = 4250;
        $unpaid->status = 'unpaid';
        $unpaid->save();

        $this->assertEquals(0.0, TuitionProgress::invoicePercent($unpaid));
    }

    public function test_paid_first_installment_is_removed_from_available_options(): void
    {
        [$student] = $this->studentWithPaidQuarterTuition();

        $this->assertEquals(25.0, TuitionProgress::percentPaid($student));
        $this->assertSame([50, 75, 100], TuitionProgress::availableInstallmentPercents($student));
    }

    public function test_disabled_invoices_and_their_rebates_are_excluded_from_student_status(): void
    {
        $staff = $this->financeStaff();
        [$student, $tuition] = $this->studentWithPaidQuarterTuition();
        $user = $student->user;
        $type = RebateType::query()->create([
            'name' => 'Staff child',
            'kind' => 'fixed',
            'default_value' => 1000,
            'is_active' => true,
        ]);

        $disabled = Invoice::query()->create([
            'number' => 'INV-DISABLED-HOSTEL',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 4000,
            'rebate_total' => 1000,
            'status' => 'cancelled',
            'disabled_reason' => 'Raised in error',
            'wallet_allowed' => true,
        ]);
        InvoiceRebate::query()->create([
            'invoice_id' => $disabled->id,
            'rebate_type_id' => $type->id,
            'kind' => 'fixed',
            'value' => 1000,
            'amount' => 1000,
            'reason' => 'Should not count',
        ]);

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->json();

        $this->assertEquals(17000.0, $payload['summary']['billed']);
        $this->assertEquals(0.0, $payload['summary']['rebate_total']);
        $this->assertEquals(4250.0, $payload['summary']['paid']);
        $this->assertEquals(12750.0, $payload['summary']['outstanding']);
        $this->assertEquals('outstanding', $payload['summary']['clearance']);
    }

    private function financeStaff(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $role = Role::query()->create([
            'name' => 'Finance',
            'slug' => 'finance-test',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['finance.invoices.manage'])->pluck('id'),
        );

        $office = OfficeDepartment::query()->create([
            'name' => 'Bursary',
            'code' => 'BUR-FIN',
            'is_active' => true,
        ]);
        $office->syncNavKeys(['finance']);

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'FIN-1',
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }

    private function studentOnProgrammeWithSchoolFees(float $amount): Student
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'Computer Science']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $fee = FeeItem::query()->create([
            'name' => 'School fees',
            'category' => 'tuition',
            'amount' => $amount,
            'is_active' => true,
        ]);
        $program->programmeFees()->create([
            'fee_item_id' => $fee->id,
            'amount' => null,
            'level_code' => 'all',
            'semester' => 'both',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Oyindamola',
            'last_name' => 'Oladejo',
            'matric_number' => 'BUT/2026/M/0002',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 800]);

        return $student->fresh(['user', 'program']);
    }

    /**
     * @return array{0: Student, 1: Invoice}
     */
    private function studentWithPaidQuarterTuition(): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BUT/2026/0001',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create([
            'student_id' => $student->id,
            'balance' => 5750,
        ]);

        $session = AcademicSession::query()->create([
            'label' => '2025/2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-09-30',
        ]);
        AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $tuition = Invoice::query()->create([
            'number' => 'INV-20260825-TUITION',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'installment_percent' => 25,
            'amount' => 4250,
            'full_amount' => 17000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
            'academic_session_id' => $session->id,
            'level_code' => '100',
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $tuition->id,
            'method' => 'wallet',
            'amount' => 4250,
            'status' => 'successful',
            'reference' => 'WALLET-'.$tuition->number,
            'receipt_no' => 'RCP-TEST25',
            'purpose' => 'tuition',
        ]);

        return [$student, $tuition];
    }
}
