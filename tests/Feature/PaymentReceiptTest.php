<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoice_receipt_shows_official_bursary_layout(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-RECEIPT-1',
            'user_id' => $user->id,
            'category' => 'application_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
        ]);
        $invoice->items()->create([
            'description' => 'Application fee',
            'amount' => 5000,
        ]);
        $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'successful',
            'reference' => 'PSK-RCP-1',
            'receipt_no' => 'RCP-TEST01',
            'purpose' => 'application_fee',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/invoices/'.$invoice->id.'/receipt')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Official Receipt', $html);
        $this->assertStringNotContainsString('Official bursary document', $html);
        $this->assertStringNotContainsString('Cashier / Teller', $html);
        $this->assertStringContainsString('For: Bursar', $html);
        $this->assertStringContainsString('Bursary Department', $html);
        $this->assertStringContainsString('RCP-TEST01', $html);
        $this->assertStringContainsString('Ada Okoye', $html);
        $this->assertStringContainsString('Application fee', $html);
        $this->assertStringContainsString('Five Thousand Naira Only', $html);
        $this->assertStringContainsString('INV-RECEIPT-1', $html);
        $this->assertStringContainsString('data:image/svg+xml', $html);
        $this->assertStringContainsString('/api/receipts/RCP-TEST01/verify', $html);
        $this->assertStringContainsString('signature=', $html);
        $this->assertStringContainsString('Scan to verify', $html);
        $this->assertStringContainsString('Scan the QR code to confirm this receipt online', $html);
    }

    public function test_returning_student_receipt_prefers_matric_over_jamb_without_course_or_level(): void
    {
        $user = User::factory()->create([
            'name' => 'Chioma Okoye',
            'status' => 'active',
            'jamb_registration' => '12345678AB',
        ]);
        $campus = \App\Models\Campus::query()->firstOrCreate(['name' => 'Main'], ['is_active' => true]);
        $faculty = \App\Models\Faculty::query()->firstOrCreate(
            ['name' => 'College of Natural Sciences'],
            ['campus_id' => $campus->id, 'is_active' => true],
        );
        $department = \App\Models\Department::query()->firstOrCreate(
            ['name' => 'Computer Science', 'faculty_id' => $faculty->id],
            ['is_active' => true],
        );
        $program = \App\Models\Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'CSC-RCP',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $student = \App\Models\Student::query()->create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'first_name' => 'Chioma',
            'last_name' => 'Okoye',
            'matric_number' => '2024/000111',
            'current_level' => '400 Level',
            'status' => 'active',
        ]);
        $invoice = Invoice::query()->create([
            'number' => 'INV-RETURN-RCP',
            'user_id' => $user->id,
            'student_id' => null, // application-fee style invoice before student_id is set
            'category' => 'application_fee',
            'amount' => 7000,
            'full_amount' => 7000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
            'level_code' => null,
        ]);
        $invoice->items()->create(['description' => 'Application fee', 'amount' => 7000]);
        $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'wema',
            'amount' => 7000, // gateway may have charged 7350; receipt stays at invoice amount
            'status' => 'successful',
            'reference' => 'WEMA-RETURN01',
            'receipt_no' => 'RCP-RETURN01',
            'purpose' => 'application_fee',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/invoices/'.$invoice->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Matric number', $html);
        $this->assertStringContainsString('2024/000111', $html);
        $this->assertStringNotContainsString('JAMB number', $html);
        $this->assertStringNotContainsString('12345678AB', $html);
        // Application fee is paid before admission — do not show prior course/level.
        $this->assertStringNotContainsString('>Course<', $html);
        $this->assertStringNotContainsString('B.Sc Computer Science', $html);
        $this->assertStringNotContainsString('>Level<', $html);
        $this->assertStringNotContainsString('400 Level', $html);
        $this->assertStringContainsString('7,000.00', $html);
        $this->assertStringNotContainsString('7,350', $html);
        $this->assertSame($student->id, $user->fresh()->student->id);
    }

    public function test_tuition_receipt_lists_fee_component_particulars(): void
    {
        $user = User::factory()->create(['name' => 'Bola Adebayo', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-COMP-RCP',
            'user_id' => $user->id,
            'category' => 'tuition',
            'installment_percent' => 25,
            'amount' => 25000,
            'full_amount' => 100000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
            'level_code' => '200 Level',
        ]);
        $invoice->items()->create(['description' => 'Tuition (25%)', 'amount' => 20000]);
        $invoice->items()->create(['description' => 'BUPF (25%)', 'amount' => 3000]);
        $invoice->items()->create(['description' => 'BUSA levy (25%)', 'amount' => 2000]);
        $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'wallet',
            'amount' => 25000,
            'status' => 'successful',
            'reference' => 'WALLET-COMP-RCP',
            'receipt_no' => 'RCP-COMP01',
            'purpose' => 'tuition',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/invoices/'.$invoice->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Particulars', $html);
        $this->assertStringContainsString('Tuition (25%)', $html);
        $this->assertStringContainsString('BUPF (25%)', $html);
        $this->assertStringContainsString('BUSA levy (25%)', $html);
        $this->assertStringContainsString('Level', $html);
        $this->assertStringContainsString('200 Level', $html);
    }

    public function test_tuition_receipt_includes_installment_percent(): void
    {
        $user = User::factory()->create(['name' => 'Bola Adebayo', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-RECEIPT-TUI',
            'user_id' => $user->id,
            'category' => 'tuition',
            'installment_percent' => 25,
            'amount' => 2250,
            'full_amount' => 9000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);
        $invoice->items()->create([
            'description' => 'Tuition 25%',
            'amount' => 2250,
        ]);
        $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'wallet',
            'amount' => 2250,
            'status' => 'successful',
            'reference' => 'WALLET-INV-RECEIPT-TUI',
            'receipt_no' => 'RCP-TUI25',
            'purpose' => 'tuition',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/invoices/'.$invoice->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Tuition (25%)', $html);
        $this->assertStringContainsString('RCP-TUI25', $html);
    }

    public function test_wallet_receipt_shows_amount_in_words(): void
    {
        $user = User::factory()->create(['name' => 'Chidi Eze', 'status' => 'active']);
        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 10000.50,
            'status' => 'successful',
            'reference' => 'PSK-WLT-1',
            'receipt_no' => 'RCP-WLT01',
            'purpose' => 'wallet_topup',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/payments/'.$payment->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Wallet funding receipt', $html);
        $this->assertStringContainsString('RCP-WLT01', $html);
        $this->assertStringContainsString('Ten Thousand Naira and Fifty Kobo Only', $html);
        $this->assertStringContainsString('Chidi Eze', $html);
        $this->assertStringContainsString('data:image/svg+xml', $html);
        $this->assertStringContainsString('/api/receipts/RCP-WLT01/verify', $html);
        $this->assertStringContainsString('signature=', $html);
    }

    public function test_staff_can_view_another_students_paid_invoice_receipt_with_breakdown(): void
    {
        $payer = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-STAFF-RCP',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 15000,
            'full_amount' => 15000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);
        $invoice->items()->create(['description' => 'Tuition', 'amount' => 10000]);
        $invoice->items()->create(['description' => 'ICT', 'amount' => 5000]);
        $invoice->payments()->create([
            'user_id' => $payer->id,
            'method' => 'wallet',
            'amount' => 15000,
            'status' => 'successful',
            'reference' => 'WALLET-STAFF-RCP',
            'receipt_no' => 'RCP-STAFF01',
            'purpose' => 'tuition',
        ]);

        $staff = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'finance.invoices.manage'],
            ['module' => 'finance', 'label' => 'Manage invoices'],
        );
        $role = Role::query()->create([
            'name' => 'Bursary',
            'slug' => 'bursary-receipt',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $staff->roles()->attach($role->id);

        Sanctum::actingAs($staff->fresh(['roles.permissions']));
        $html = $this->get('/api/invoices/'.$invoice->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Official Receipt', $html);
        $this->assertStringContainsString('Particulars', $html);
        $this->assertStringContainsString('Tuition', $html);
        $this->assertStringContainsString('ICT', $html);
        $this->assertStringContainsString('RCP-STAFF01', $html);
        $this->assertStringContainsString('Ada Okoye', $html);
    }

    public function test_partial_invoice_payment_still_prints_a_receipt(): void
    {
        $user = User::factory()->create(['name' => 'Emeka Obi', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-PARTIAL-RCP',
            'user_id' => $user->id,
            'category' => 'tuition',
            'amount' => 20000,
            'full_amount' => 40000,
            'balance' => 20000,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        $invoice->items()->create(['description' => 'Tuition 50%', 'amount' => 20000]);
        $payment = $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => 'wallet',
            'amount' => 20000,
            'status' => 'successful',
            'reference' => 'WALLET-PARTIAL-RCP',
            'receipt_no' => 'RCP-PARTIAL01',
            'purpose' => 'tuition',
        ]);

        Sanctum::actingAs($user);
        $html = $this->get('/api/payments/'.$payment->id.'/receipt')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Official Receipt', $html);
        $this->assertStringContainsString('RCP-PARTIAL01', $html);
        $this->assertStringContainsString('Emeka Obi', $html);
        $this->assertStringContainsString('Twenty Thousand Naira Only', $html);
    }
}
