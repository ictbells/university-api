<?php

namespace Tests\Feature;

use App\Models\ClinicVisit;
use App\Models\ClinicVisitItem;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\MedicalProfile;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvoiceService;
use App\Support\FeeSchedule;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicCatalogBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_clinic_category_is_operational_and_distinct_from_medical_levy(): void
    {
        $this->assertContains('clinic', FeeSchedule::operationalCategories());
        $this->assertNotContains('clinic', FeeSchedule::scheduleCategories());
        $this->assertContains('medical', FeeSchedule::scheduleCategories());
        $this->assertSame('Clinic services', FeeSchedule::label('clinic'));
        $this->assertSame('Medical levy', FeeSchedule::label('medical'));
    }

    public function test_adding_a_visit_item_snapshots_catalog_price_and_rejects_typed_amounts(): void
    {
        [$staff, $visit, $fee] = $this->clinicContext();
        Sanctum::actingAs($staff);

        $this->getJson('/api/fees?category=clinic&active=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $fee->id, 'name' => 'Consultation']);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 2,
            'unit_amount' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_amount']);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'description' => 'Typed line',
        ])->assertStatus(422)->assertJsonValidationErrors(['description']);

        $tuition = FeeItem::query()->create([
            'name' => 'Tuition',
            'category' => 'tuition',
            'amount' => 50000,
            'wallet_allowed' => true,
            'is_active' => true,
        ]);
        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $tuition->id,
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['fee_item_id']);

        $created = $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('description', 'Consultation')
            ->assertJsonPath('fee_item_id', $fee->id)
            ->json();

        $this->assertEquals(2500, (float) $created['unit_amount']);
        $this->assertEquals(5000, (float) $created['line_total']);

        $fee->update(['amount' => 99999, 'name' => 'Renamed']);
        $item = ClinicVisitItem::query()->find($created['id']);
        $this->assertEquals(2500, (float) $item->unit_amount);
        $this->assertSame('Consultation', $item->description);

        $this->patchJson("/api/clinic/visit-items/{$item->id}", [
            'unit_amount' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_amount']);

        $this->patchJson("/api/clinic/visit-items/{$item->id}", [
            'quantity' => 3,
        ])->assertOk()
            ->assertJsonPath('unit_amount', '2500.00')
            ->assertJsonPath('line_total', '7500.00');
    }

    public function test_finalize_invoices_clinic_lines_and_wallet_payment_updates_the_bill(): void
    {
        [$staff, $visit, $fee, $studentUser] = $this->clinicContext();
        Sanctum::actingAs($staff);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 2,
            'nhis_covered' => false,
        ])->assertCreated();

        $this->postJson("/api/clinic/visits/{$visit->id}/finalize-bill")
            ->assertCreated()
            ->assertJsonPath('status', 'unpaid')
            ->assertJsonPath('student_payable_amount', '5000.00');

        $bill = MedicalBill::query()->first();
        $invoice = Invoice::query()->find($bill->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertSame('clinic', $invoice->category);
        $this->assertEquals(5000, (float) $invoice->amount);
        $this->assertEquals(5000, (float) $invoice->balance);
        $this->assertTrue($invoice->wallet_allowed);
        $this->assertSame('Consultation', $invoice->items()->first()->description);
        $this->assertEquals(5000, (float) $invoice->items()->first()->amount);

        Wallet::query()->create([
            'student_id' => $visit->student_id,
            'balance' => 20000,
        ]);

        Sanctum::actingAs($studentUser);
        $this->postJson('/api/payments/paystack/initialize', ['invoice_id' => $invoice->id])
            ->assertStatus(422);

        $this->getJson('/api/me/clinic')
            ->assertOk()
            ->assertJsonPath('invoices.0.category', 'clinic')
            ->assertJsonPath('invoices.0.amount', '5000.00');

        $this->postJson('/api/wallet/pay/'.$invoice->id)->assertOk();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_finalize_invoice_amount_is_student_payable_after_nhis(): void
    {
        [$staff, $visit, $fee] = $this->clinicContext();
        MedicalProfile::query()->updateOrCreate(
            ['student_id' => $visit->student_id],
            ['nhis_enrolled' => true, 'nhis_coverage_percent' => 90]
        );
        Sanctum::actingAs($staff);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 1,
            'nhis_covered' => true,
        ])->assertCreated();

        $this->postJson("/api/clinic/visits/{$visit->id}/finalize-bill")
            ->assertCreated()
            ->assertJsonPath('nhis_applied', true)
            ->assertJsonPath('gross_amount', '2500.00')
            ->assertJsonPath('nhis_covered_amount', '2250.00')
            ->assertJsonPath('student_payable_amount', '250.00');

        $invoice = Invoice::query()->where('category', 'clinic')->first();
        $this->assertEquals(250, (float) $invoice->amount);
        $this->assertEquals(250, (float) $invoice->balance);
        $this->assertEquals(
            -2250,
            (float) $invoice->items()->where('description', 'NHIS coverage')->value('amount')
        );
    }

    public function test_null_coverage_override_does_not_wipe_nhis_split(): void
    {
        [$staff, $visit, $fee] = $this->clinicContext();
        MedicalProfile::query()->updateOrCreate(
            ['student_id' => $visit->student_id],
            ['nhis_enrolled' => true, 'nhis_coverage_percent' => 90]
        );
        Sanctum::actingAs($staff);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 1,
            'nhis_covered' => true,
        ])->assertCreated();

        $this->postJson("/api/clinic/visits/{$visit->id}/finalize-bill", [
            'coverage_percent_override' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('nhis_applied', true)
            ->assertJsonPath('gross_amount', '2500.00')
            ->assertJsonPath('nhis_covered_amount', '2250.00')
            ->assertJsonPath('student_payable_amount', '250.00');

        $invoice = Invoice::query()->where('category', 'clinic')->first();
        $this->assertEquals(250, (float) $invoice->amount);
        $this->assertEquals(250, (float) $invoice->balance);
    }

    public function test_disabled_clinic_invoice_can_be_replaced_by_finalizing_again(): void
    {
        [$staff, $visit, $fee] = $this->clinicContext();
        MedicalProfile::query()->updateOrCreate(
            ['student_id' => $visit->student_id],
            ['nhis_enrolled' => true, 'nhis_coverage_percent' => 90]
        );
        Sanctum::actingAs($staff);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 1,
            'nhis_covered' => true,
        ])->assertCreated();

        $this->postJson("/api/clinic/visits/{$visit->id}/finalize-bill", [
            'coverage_percent_override' => null,
        ])->assertCreated();

        $first = Invoice::query()->where('category', 'clinic')->first();
        $this->assertNotNull($first);
        app(InvoiceService::class)->disable($first, 'Wrong amount billed before NHIS split.');

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', MedicalBill::query()->where('invoice_id', $first->id)->value('status'));

        $replaced = $this->postJson("/api/clinic/visits/{$visit->id}/finalize-bill")
            ->assertOk()
            ->assertJsonPath('student_payable_amount', '250.00')
            ->json();

        $this->assertNotEquals($first->id, $replaced['invoice_id']);
        $second = Invoice::query()->find($replaced['invoice_id']);
        $this->assertEquals(250, (float) $second->amount);
        $this->assertSame('unpaid', $second->status);
        $this->assertSame($second->id, (int) MedicalBill::query()->where('clinic_visit_id', $visit->id)->value('invoice_id'));
    }

    public function test_finalize_uses_fixed_nhis_cover_amount(): void
    {
        [$staff, $visit, $fee] = $this->clinicContext();
        MedicalProfile::query()->updateOrCreate(
            ['student_id' => $visit->student_id],
            ['nhis_enrolled' => true, 'nhis_coverage_amount' => 1000]
        );
        Sanctum::actingAs($staff);

        $this->postJson("/api/clinic/visits/{$visit->id}/items", [
            'fee_item_id' => $fee->id,
            'quantity' => 1,
            'nhis_covered' => true,
        ])->assertCreated();

        $this->postJson("/api/clinic/visits/{$visit->id}/finalize-bill")
            ->assertCreated()
            ->assertJsonPath('nhis_applied', true)
            ->assertJsonPath('gross_amount', '2500.00')
            ->assertJsonPath('nhis_covered_amount', '1000.00')
            ->assertJsonPath('student_payable_amount', '1500.00');
    }

    /**
     * @return array{0: User, 1: ClinicVisit, 2: FeeItem, 3: User}
     */
    private function clinicContext(): array
    {
        $staff = $this->staffUser(['medical.view_any', 'medical.manage', 'medical.billing']);
        $studentUser = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'matric_number' => 'BUT/2026/R/'.substr(sha1(uniqid()), 0, 4),
            'status' => 'active',
        ]);
        $visit = ClinicVisit::query()->create([
            'student_id' => $student->id,
            'status' => 'in_progress',
            'visit_type' => 'walk_in',
            'visited_on' => now()->toDateString(),
        ]);
        $fee = FeeItem::query()->create([
            'name' => 'Consultation',
            'category' => 'clinic',
            'amount' => 2500,
            'wallet_allowed' => true,
            'is_active' => true,
        ]);

        return [$staff, $visit, $fee, $studentUser];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Clinic staff',
            'slug' => 'clinic-'.substr(sha1(uniqid()), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);
        $office = OfficeDepartment::query()->create([
            'name' => 'Clinic '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
