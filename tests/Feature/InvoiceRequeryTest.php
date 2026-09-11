<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceRequeryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.wema.public' => 'pk_wema_test',
            'services.wema.secret' => 'sk_wema_test',
            'services.wema.business_id' => 'biz-wema_test',
            'services.wema.base' => 'https://apibox.alatpay.ng',
            'services.paystack.allow_demo_fulfill' => false,
        ]);
        PaymentGatewaySettings::update(['payment_gateway' => 'wema']);
    }

    public function test_staff_can_requery_pending_wema_payment_and_mark_invoice_paid(): void
    {
        $staff = $this->financeStaff();
        $payer = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'BUT/2026/REQUERY1',
            'user_id' => $payer->id,
            'category' => 'acceptance_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'wema',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'WEMA-REQUERY001',
            'paystack_reference' => 'tx-requery-001',
            'purpose' => 'acceptance_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-requery-001' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-requery-001',
                    'status' => 'completed',
                    'amount' => 5000,
                    'orderId' => 'WEMA-REQUERY001',
                ],
            ]),
        ]);

        Sanctum::actingAs($staff);
        $this->postJson('/api/invoices/'.$invoice->id.'/requery')
            ->assertOk()
            ->assertJsonPath('status', 'successful')
            ->assertJsonPath('receipt_no', 'BUT/2026/REQUERY1');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('successful', Payment::query()->where('reference', 'WEMA-REQUERY001')->value('status'));
    }

    public function test_requery_finds_completed_alatpay_transaction_by_order_reference(): void
    {
        $staff = $this->financeStaff();
        $payer = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'BUT/2026/REQUERY3',
            'user_id' => $payer->id,
            'category' => 'acceptance_fee',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 100000,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'wema',
            'amount' => 100000,
            'status' => 'pending',
            'reference' => 'WEMA-MC97CMEL3UBZ',
            'paystack_reference' => 'WEMA-MC97CMEL3UBZ',
            'purpose' => 'acceptance_fee',
            'created_at' => now()->subHour(),
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, '/transactions/tx-from-list-001')) {
                return Http::response([
                    'status' => true,
                    'message' => 'Success',
                    'data' => [
                        'id' => 'tx-from-list-001',
                        'status' => 'completed',
                        'amount' => 100000,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                        'metadata' => ['orderId' => 'WEMA-MC97CMEL3UBZ'],
                    ],
                ]);
            }
            if (str_contains($url, '/alatpaytransaction/api/v1/transactions')) {
                return Http::response([
                    'status' => true,
                    'message' => 'Success',
                    'data' => [
                        [
                            'id' => 'tx-from-list-001',
                            'status' => 'completed',
                            'amount' => 100000,
                            'orderId' => 'BELLSUNIVERSITY-internal',
                            'metadata' => ['orderId' => 'WEMA-MC97CMEL3UBZ'],
                        ],
                    ],
                    'pagination' => ['currentPage' => 1, 'totalPages' => 1],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        Sanctum::actingAs($staff);
        $this->postJson('/api/invoices/'.$invoice->id.'/requery')
            ->assertOk()
            ->assertJsonPath('status', 'successful');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('tx-from-list-001', Payment::query()->where('reference', 'WEMA-MC97CMEL3UBZ')->value('paystack_reference'));
    }

    public function test_requery_finds_completed_alatpay_transaction_when_metadata_is_json_string(): void
    {
        $staff = $this->financeStaff();
        $payer = User::factory()->create(['name' => 'David Fabuyi', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'BUT/2026/STRINGMETA',
            'user_id' => $payer->id,
            'category' => 'acceptance_fee',
            'amount' => 100000,
            'full_amount' => 100000,
            'balance' => 100000,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $payer->id,
            'method' => 'wema',
            'amount' => 100000,
            'status' => 'pending',
            'reference' => 'WEMA-AUZ5SBSKX0CY',
            'paystack_reference' => 'WEMA-AUZ5SBSKX0CY',
            'purpose' => 'acceptance_fee',
            'created_at' => now()->subHour(),
        ]);

        $invoiceId = (string) $invoice->id;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($invoiceId) {
            $url = $request->url();
            $meta = '{"orderId":"WEMA-AUZ5SBSKX0CY","invoice_id":"'.$invoiceId.'","purpose":"acceptance_fee"}';
            if (str_contains($url, '/transactions/e8d831b2-04dc-4a5c-ba39-ca7c5d97cd98')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => 'e8d831b2-04dc-4a5c-ba39-ca7c5d97cd98',
                        'status' => 'completed',
                        'amount' => 100350,
                        'feeAmount' => 350,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                        // Dashboard/API often return metadata as a JSON string.
                        'metadata' => $meta,
                    ],
                ]);
            }
            if (str_contains($url, '/alatpaytransaction/api/v1/transactions')) {
                return Http::response([
                    'status' => true,
                    'data' => [[
                        'id' => 'e8d831b2-04dc-4a5c-ba39-ca7c5d97cd98',
                        'status' => 'completed',
                        'amount' => 100350,
                        'feeAmount' => 350,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                        'metadata' => $meta,
                    ]],
                    'pagination' => ['totalPages' => 1],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        Sanctum::actingAs($staff);
        $this->postJson('/api/invoices/'.$invoice->id.'/requery')
            ->assertOk()
            ->assertJsonPath('status', 'successful');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(
            'e8d831b2-04dc-4a5c-ba39-ca7c5d97cd98',
            Payment::query()->where('reference', 'WEMA-AUZ5SBSKX0CY')->value('paystack_reference')
        );
    }

    public function test_requery_without_pending_payment_returns_422(): void
    {
        $staff = $this->financeStaff();
        $payer = User::factory()->create(['status' => 'active']);
        $invoice = Invoice::query()->create([
            'number' => 'BUT/2026/REQUERY2',
            'user_id' => $payer->id,
            'category' => 'acceptance_fee',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 5000,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);

        Sanctum::actingAs($staff);
        $this->postJson('/api/invoices/'.$invoice->id.'/requery')
            ->assertStatus(422)
            ->assertJsonPath('message', 'No pending online payment found for this invoice.');
    }

    private function financeStaff(): User
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $role = Role::query()->create([
            'name' => 'Finance',
            'slug' => 'finance-requery-'.uniqid(),
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
