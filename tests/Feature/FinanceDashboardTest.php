<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_finance_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/finance/dashboard')->assertForbidden();
    }

    public function test_dashboard_builds_university_statement(): void
    {
        $staff = $this->financeStaff();
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BUT/2026/DASH',
            'status' => 'active',
            'current_level' => '100',
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 3000]);

        $tuition = Invoice::query()->create([
            'number' => 'INV-DASH-TUI',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'tuition',
            'amount' => 10000,
            'full_amount' => 40000,
            'balance' => 2500,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $tuition->id,
            'method' => 'paystack',
            'amount' => 7500,
            'status' => 'successful',
            'reference' => 'PSK-DASH-TUI',
            'receipt_no' => 'RCP-DASH-TUI',
            'purpose' => 'tuition',
        ]);

        Invoice::query()->create([
            'number' => 'INV-DASH-HOSTEL',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
            'wallet_allowed' => true,
        ]);

        Payment::query()->create([
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 2000,
            'status' => 'successful',
            'reference' => 'PSK-DASH-TOPUP',
            'receipt_no' => 'RCP-DASH-TOPUP',
            'purpose' => 'wallet_topup',
        ]);

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/dashboard')
            ->assertOk()
            ->json();

        $this->assertEquals('All time', $payload['period']['label']);
        $this->assertEquals(15000.0, $payload['totals']['invoiced']);
        $this->assertEquals(7500.0, $payload['totals']['collected']);
        $this->assertEquals(9500.0, $payload['totals']['receipts']);
        $this->assertEquals(9500.0, $payload['totals']['cash_received']);
        $this->assertEquals(2000.0, $payload['totals']['wallet_inflows']);
        $this->assertEquals(0.0, $payload['totals']['wallet_applied']);
        $this->assertEquals(7500.0, $payload['totals']['outstanding']);
        $this->assertEquals(3000.0, $payload['totals']['wallet_liability']);

        $tuitionRow = collect($payload['by_category'])->firstWhere('category', 'tuition');
        $this->assertEquals(10000.0, $tuitionRow['invoiced']);
        $this->assertEquals(7500.0, $tuitionRow['collected']);
        $this->assertEquals(2500.0, $tuitionRow['outstanding']);

        $this->assertTrue(collect($payload['by_method'])->contains(
            fn ($row) => $row['method'] === 'paystack' && (float) $row['amount'] === 9500.0
        ));
        $this->assertCount(2, $payload['recent_payments']);
    }

    public function test_dashboard_period_filter_excludes_older_activity(): void
    {
        $staff = $this->financeStaff();
        $user = User::factory()->create(['status' => 'active']);
        $old = Invoice::query()->create([
            'number' => 'INV-OLD',
            'user_id' => $user->id,
            'category' => 'application_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $old->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'successful',
            'reference' => 'PSK-OLD',
            'purpose' => 'application_fee',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
        $fresh = Invoice::query()->create([
            'number' => 'INV-NEW',
            'user_id' => $user->id,
            'category' => 'acceptance_fee',
            'amount' => 7000,
            'full_amount' => 7000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => false,
        ]);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $fresh->id,
            'method' => 'paystack',
            'amount' => 7000,
            'status' => 'successful',
            'reference' => 'PSK-NEW',
            'purpose' => 'acceptance_fee',
        ]);

        Sanctum::actingAs($staff);
        $payload = $this->getJson('/api/finance/dashboard?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->json();

        $this->assertEquals(7000.0, $payload['totals']['invoiced']);
        $this->assertEquals(7000.0, $payload['totals']['collected']);
        $this->assertEquals(0.0, $payload['totals']['outstanding']);
    }

    public function test_statement_pdf_export_succeeds(): void
    {
        $staff = $this->financeStaff();
        Sanctum::actingAs($staff);

        $this->get('/api/finance/dashboard/export?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function financeStaff(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Finance',
            'slug' => 'finance-dashboard-'.uniqid(),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['finance.invoices.manage'])->pluck('id'),
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
