<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceGatewayExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_list_filters_by_successful_payment_gateway(): void
    {
        $staff = $this->financeUser();
        $payer = User::factory()->create(['status' => 'active']);

        $paystackInvoice = Invoice::query()->create([
            'number' => 'INV-PSK',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 10000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $paystackInvoice->id,
            'method' => 'paystack',
            'amount' => 10000,
            'status' => 'successful',
            'reference' => 'PSK-OK-1',
            'purpose' => 'tuition',
        ]);

        $wemaInvoice = Invoice::query()->create([
            'number' => 'INV-WEMA',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 8000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $wemaInvoice->id,
            'method' => 'wema',
            'amount' => 8000,
            'status' => 'successful',
            'reference' => 'WEMA-OK-1',
            'purpose' => 'tuition',
        ]);

        $pendingInvoice = Invoice::query()->create([
            'number' => 'INV-PENDING',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $pendingInvoice->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'PSK-PENDING',
            'purpose' => 'tuition',
        ]);

        Sanctum::actingAs($staff);

        $paystack = $this->getJson('/api/invoices?gateway=paystack')->assertOk();
        $this->assertEquals(1, $paystack->json('total'));
        $this->assertEquals('INV-PSK', $paystack->json('data.0.number'));

        $wema = $this->getJson('/api/invoices?gateway=wema')->assertOk();
        $this->assertEquals(1, $wema->json('total'));
        $this->assertEquals('INV-WEMA', $wema->json('data.0.number'));
    }

    public function test_gateway_export_lists_only_successful_payments_with_gateway_column(): void
    {
        $staff = $this->financeUser();
        $payer = User::factory()->create(['name' => 'Ada Payer', 'status' => 'active']);

        $paystackInvoice = Invoice::query()->create([
            'number' => 'INV-PSK-2',
            'user_id' => $payer->id,
            'category' => 'acceptance_fee',
            'amount' => 7000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $paystackInvoice->id,
            'method' => 'paystack',
            'amount' => 7000,
            'status' => 'successful',
            'reference' => 'PSK-EXPORT-1',
            'receipt_no' => 'RCP-PSK-1',
            'purpose' => 'acceptance_fee',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $paystackInvoice->id,
            'method' => 'wema',
            'amount' => 1000,
            'status' => 'successful',
            'reference' => 'WEMA-SHOULD-EXCLUDE',
            'purpose' => 'acceptance_fee',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $paystackInvoice->id,
            'method' => 'paystack',
            'amount' => 500,
            'status' => 'pending',
            'reference' => 'PSK-PENDING-EXPORT',
            'purpose' => 'acceptance_fee',
        ]);

        Sanctum::actingAs($staff);

        $csv = $this->get('/api/invoices/export?format=csv&gateway=paystack')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Gateway', $body);
        $this->assertStringContainsString('Paystack', $body);
        $this->assertStringContainsString('PSK-EXPORT-1', $body);
        $this->assertStringContainsString('RCP-PSK-1', $body);
        $this->assertStringContainsString('1 record(s)', $body);
        $this->assertStringNotContainsString('WEMA-SHOULD-EXCLUDE', $body);
        $this->assertStringNotContainsString('PSK-PENDING-EXPORT', $body);

        $excel = $this->get('/api/invoices/export?format=excel&gateway=paystack')->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $excel->headers->get('content-type'),
        );
        $this->assertNotSame('', $excel->streamedContent());
    }

    public function test_invoice_export_includes_gateway_column_without_gateway_filter(): void
    {
        $staff = $this->financeUser();
        $payer = User::factory()->create(['status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'INV-GATEWAY-COL',
            'user_id' => $payer->id,
            'category' => 'tuition',
            'amount' => 4000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        Payment::query()->create([
            'user_id' => $payer->id,
            'invoice_id' => $invoice->id,
            'method' => 'paygate',
            'amount' => 4000,
            'status' => 'successful',
            'reference' => 'PG-COL-1',
            'purpose' => 'tuition',
        ]);

        Sanctum::actingAs($staff);
        $body = $this->get('/api/invoices/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Gateway', $body);
        $this->assertStringContainsString('PayGate', $body);
        $this->assertStringContainsString('INV-GATEWAY-COL', $body);
    }

    private function financeUser(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Bursary',
            'slug' => 'bursary-gateway-export-'.uniqid(),
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
            'staff_number' => 'STF-GW-'.$user->id,
            'title' => 'Bursar',
        ]);

        return $user->fresh(['roles', 'staff']);
    }
}
