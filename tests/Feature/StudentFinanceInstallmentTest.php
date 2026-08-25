<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\OfficeDepartment;
use App\Models\Payment;
use App\Models\Permission;
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

    public function test_student_status_bills_installment_amount_not_full_year_fee(): void
    {
        $staff = $this->financeStaff();
        [$student, $tuition] = $this->studentWithPaidQuarterTuition();

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/student-status?student_id='.$student->id)
            ->assertOk()
            ->json();

        $this->assertEquals(4250.0, $payload['summary']['billed']);
        $this->assertEquals(4250.0, $payload['summary']['paid']);
        $this->assertEquals(0.0, $payload['summary']['outstanding']);

        $invoiceRow = collect($payload['invoices'])->firstWhere('id', $tuition->id);
        $this->assertEquals(4250.0, $invoiceRow['amount']);
        $this->assertEquals(4250.0, $invoiceRow['amount_paid']);
        $this->assertEquals(0.0, $invoiceRow['balance']);
        $this->assertEquals('paid', $invoiceRow['status']);
        $this->assertEquals(17000.0, $invoiceRow['full_amount']);
        $this->assertEquals(25, $invoiceRow['installment_percent']);

        $paymentAmounts = collect($payload['payments'])->pluck('amount')->map(fn ($v) => (float) $v)->all();
        $this->assertEquals([4250.0], $paymentAmounts);
        $this->assertFalse(
            collect($payload['payments'])->contains(fn ($row) => ($row['method'] ?? '') === 'recorded'),
            'Must not invent a recorded payment for the unpaid remainder of full_amount.',
        );
    }

    public function test_student_status_recalculates_balance_from_receipts_when_status_is_wrong(): void
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
            'amount' => 17000,
            'full_amount' => 68000,
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

        $invoiceRow = collect($payload['invoices'])->firstWhere('id', $tuition->id);
        $this->assertEquals(17000.0, $invoiceRow['amount']);
        $this->assertEquals(4250.0, $invoiceRow['amount_paid']);
        $this->assertEquals(12750.0, $invoiceRow['balance']);
        $this->assertEquals('partial', $invoiceRow['status']);

        $this->assertEquals(12750.0, (float) $tuition->fresh()->balance);
        $this->assertEquals('partial', $tuition->fresh()->status);
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
