<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceListTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_list_returns_filtered_amount_totals(): void
    {
        $user = $this->financeUser();
        Sanctum::actingAs($user);

        Invoice::query()->create([
            'number' => 'INV-A',
            'user_id' => $user->id,
            'category' => 'school_fees',
            'amount' => 10000,
            'balance' => 4000,
            'rebate_total' => 1000,
            'status' => 'partial',
        ]);
        Invoice::query()->create([
            'number' => 'INV-B',
            'user_id' => $user->id,
            'category' => 'acceptance_fee',
            'amount' => 5000,
            'balance' => 5000,
            'rebate_total' => 0,
            'status' => 'unpaid',
        ]);
        Invoice::query()->create([
            'number' => 'INV-C',
            'user_id' => $user->id,
            'category' => 'school_fees',
            'amount' => 8000,
            'balance' => 0,
            'rebate_total' => 0,
            'status' => 'paid',
        ]);

        $all = $this->getJson('/api/invoices')->assertOk();
        $this->assertEquals(23000.0, (float) $all->json('totals.amount'));
        $this->assertEquals(9000.0, (float) $all->json('totals.balance'));
        $this->assertEquals(1000.0, (float) $all->json('totals.rebate_total'));

        $filtered = $this->getJson('/api/invoices?status=unpaid')->assertOk();
        $this->assertEquals(1, $filtered->json('total'));
        $this->assertEquals(5000.0, (float) $filtered->json('totals.amount'));
        $this->assertEquals(5000.0, (float) $filtered->json('totals.balance'));
    }

    private function financeUser(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Bursary',
            'slug' => 'bursary-invoice-totals',
            'is_system' => false,
            'is_active' => true,
        ]);
        $manage = Permission::query()->where('key', 'finance.invoices.manage')->first();
        if ($manage) {
            $role->permissions()->sync([$manage->id]);
        }
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'STF-INV-'.$user->id,
            'title' => 'Bursar',
        ]);

        return $user->fresh(['roles', 'staff']);
    }
}
