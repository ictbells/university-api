<?php

namespace Tests\Feature;

use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\OfficeDepartment;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeeItemCollectionsReportTest extends TestCase
{
    use RefreshDatabase;

    private FeeItem $tuition;

    private FeeItem $sundry;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $this->tuition = FeeItem::query()->create(['name' => 'Report Tuition', 'category' => 'tuition', 'amount' => 20000]);
        $this->sundry = FeeItem::query()->create(['name' => 'Report Sundry', 'category' => 'sundry', 'amount' => 10000]);
        $this->seedInvoices();
    }

    public function test_grouped_by_fee_item_attributes_payments_pro_rata(): void
    {
        Sanctum::actingAs($this->financeReporter());

        $response = $this->postJson('/api/reports/run', [
            'dataset' => 'fee_item_collections',
            'group_by' => ['fee_item'],
            'aggregations' => [
                ['fn' => 'sum', 'field' => 'billed', 'as' => 'billed'],
                ['fn' => 'sum', 'field' => 'rebate', 'as' => 'rebate'],
                ['fn' => 'sum', 'field' => 'paid', 'as' => 'paid'],
                ['fn' => 'sum', 'field' => 'outstanding', 'as' => 'outstanding'],
            ],
        ])->assertOk();

        $rows = collect($response->json('rows'))->keyBy('fee_item');

        $this->assertEqualsWithDelta(40000, (float) $rows['Report Tuition']['billed'], 0.01);
        $this->assertEqualsWithDelta(2000, (float) $rows['Report Tuition']['rebate'], 0.01);
        $this->assertEqualsWithDelta(32000, (float) $rows['Report Tuition']['paid'], 0.01);
        $this->assertEqualsWithDelta(6000, (float) $rows['Report Tuition']['outstanding'], 0.01);

        $this->assertEqualsWithDelta(10000, (float) $rows['Report Sundry']['billed'], 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows['Report Sundry']['rebate'], 0.01);
        $this->assertEqualsWithDelta(6000, (float) $rows['Report Sundry']['paid'], 0.01);
        $this->assertEqualsWithDelta(3000, (float) $rows['Report Sundry']['outstanding'], 0.01);

        $this->assertFalse($rows->has('Rebate: Scholarship'));
    }

    public function test_filter_by_fee_item_totals_amount_paid(): void
    {
        Sanctum::actingAs($this->financeReporter());

        $response = $this->postJson('/api/reports/run', [
            'dataset' => 'fee_item_collections',
            'columns' => ['fee_item', 'invoice_number', 'billed', 'paid', 'outstanding'],
            'filters' => [['field' => 'fee_item', 'op' => 'eq', 'value' => 'Report Tuition']],
        ])->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertEquals(32000, $response->json('totals.paid'));
        $this->assertEquals(6000, $response->json('totals.outstanding'));
    }

    public function test_fee_item_and_category_are_dropdowns_backed_by_fee_items(): void
    {
        Sanctum::actingAs($this->financeReporter());

        $dataset = collect($this->getJson('/api/reports/datasets')->assertOk()->json('data'))
            ->firstWhere('key', 'fee_item_collections');
        $columns = collect($dataset['columns'])->keyBy('key');

        $this->assertSame('enum', $columns['fee_item']['type']);
        $this->assertContains('Report Tuition', $columns['fee_item']['options']);
        $this->assertSame('enum', $columns['fee_category']['type']);
        $this->assertContains('tuition', $columns['fee_category']['options']);
        $this->assertSame('enum', $columns['programme']['type']);
        $this->assertSame('enum', $columns['session']['type']);
        $this->assertSame('enum', $columns['level_code']['type']);
        $this->assertContains('100', $columns['level_code']['options']);

        $response = $this->postJson('/api/reports/run', [
            'dataset' => 'fee_item_collections',
            'columns' => ['fee_item', 'paid'],
            'filters' => [['field' => 'fee_item', 'op' => 'in', 'value' => ['Report Tuition', 'Report Sundry']]],
        ])->assertOk();
        $this->assertEquals(38000, $response->json('totals.paid'));

        $response = $this->postJson('/api/reports/run', [
            'dataset' => 'fee_item_collections',
            'columns' => ['fee_item', 'paid'],
            'filters' => [['field' => 'fee_category', 'op' => 'eq', 'value' => 'tuition']],
        ])->assertOk();
        $this->assertEquals(32000, $response->json('totals.paid'));
    }

    private function seedInvoices(): void
    {
        $payer = User::factory()->create(['name' => 'Fee Payer', 'status' => 'active']);

        $partial = Invoice::query()->create([
            'number' => 'INV-FIC-1',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 30000,
            'full_amount' => 30000,
            'balance' => 9000,
            'rebate_total' => 3000,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        $partial->items()->create(['fee_item_id' => $this->tuition->id, 'description' => 'Tuition', 'amount' => 20000]);
        $partial->items()->create(['fee_item_id' => $this->sundry->id, 'description' => 'Sundry', 'amount' => 10000]);
        $partial->items()->create(['fee_item_id' => null, 'description' => 'Rebate: Scholarship', 'amount' => -3000]);
        $this->pay($partial, $payer, 18000, 'successful', 'FIC-1');
        $this->pay($partial, $payer, 5000, 'failed', 'FIC-2');

        $paid = Invoice::query()->create([
            'number' => 'INV-FIC-2',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 20000,
            'full_amount' => 20000,
            'balance' => 0,
            'level_code' => '100',
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);
        $paid->items()->create(['fee_item_id' => $this->tuition->id, 'description' => 'Tuition', 'amount' => 20000]);
        $this->pay($paid, $payer, 20000, 'successful', 'FIC-3');

        $cancelled = Invoice::query()->create([
            'number' => 'INV-FIC-3',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 20000,
            'full_amount' => 20000,
            'balance' => 20000,
            'status' => 'cancelled',
            'wallet_allowed' => true,
        ]);
        $cancelled->items()->create(['fee_item_id' => $this->tuition->id, 'description' => 'Tuition', 'amount' => 20000]);
    }

    private function pay(Invoice $invoice, User $payer, float $amount, string $status, string $reference): void
    {
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'paystack',
            'amount' => $amount,
            'status' => $status,
            'reference' => $reference,
            'receipt_no' => 'RCPT-'.$reference,
            'purpose' => $invoice->category,
        ]);
    }

    private function financeReporter(): User
    {
        $permissions = ['reports.view', 'finance.invoices.manage'];
        $role = Role::query()->create([
            'name' => 'Fee item reporter',
            'slug' => 'test-fee-item-reporter',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));

        $link = \App\Models\OfficeNavLink::query()->where('nav_key', 'reports')->first();
        $office = $link
            ? (app(\App\Services\OfficeNavOwnerResolver::class)->departmentAndUnit($link->linkable)['department'] ?? null)
            : null;
        if (! $office) {
            $office = OfficeDepartment::query()->create(['name' => 'Fee report office', 'code' => 'FEERPT', 'is_active' => true]);
            $office->syncNavKeys(['home', 'reports']);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-FEERPT',
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
