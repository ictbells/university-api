<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\InvoiceSettlement;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_collected_is_invoice_receipts_not_wallet_topups(): void
    {
        $payer = User::factory()->create(['status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-REV-1',
            'user_id' => $payer->id,
            'category' => 'sundry',
            'amount' => 25000,
            'full_amount' => 25000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'wallet',
            'amount' => 25000,
            'status' => 'successful',
            'reference' => 'WALLET-INV-REV-1',
            'purpose' => 'sundry',
        ]);
        Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $payer->id,
            'method' => 'paystack',
            'amount' => 100000,
            'status' => 'successful',
            'reference' => 'TOPUP-1',
            'purpose' => 'wallet_topup',
        ]);

        $this->assertEquals(25000.0, InvoiceSettlement::collectedRevenue());

        $staff = $this->superAdmin();
        Sanctum::actingAs($staff);
        $this->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('payments_collected', 25000);
    }

    public function test_paid_invoice_without_receipt_still_counts(): void
    {
        $payer = User::factory()->create(['status' => 'active']);
        Invoice::query()->create([
            'number' => 'INV-LEGACY',
            'user_id' => $payer->id,
            'category' => 'hostel',
            'amount' => 80000,
            'full_amount' => 80000,
            'balance' => 0,
            'status' => 'paid',
            'wallet_allowed' => true,
        ]);

        $this->assertEquals(80000.0, InvoiceSettlement::collectedRevenue());
    }

    private function superAdmin(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles']);
    }
}
