<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Support\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlatpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.wema.public' => 'pk_wema_test',
            'services.wema.secret' => 'sk_wema_test',
            'services.wema.business_id' => 'biz-wema-test',
            'services.wema.base' => 'https://apibox.alatpay.ng',
            'services.paystack.allow_demo_fulfill' => false,
        ]);
        PaymentGatewaySettings::update(['payment_gateway' => 'wema']);
    }

    public function test_initialize_returns_wema_checkout_payload(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 15000);
        Sanctum::actingAs($user);

        $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('provider', 'wema')
            ->assertJsonPath('demo', false)
            ->assertJsonPath('checkout.api_key', 'pk_wema_test')
            ->assertJsonPath('checkout.business_id', 'biz-wema-test')
            ->assertJsonPath('checkout.amount', 15000)
            ->assertJsonPath('checkout.first_name', 'Ada')
            ->assertJsonPath('checkout.last_name', 'Okoye')
            ->assertJsonPath('authorization_url', null);

        $payment = Payment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame('wema', $payment->method);
        $this->assertSame('pending', $payment->status);
        $this->assertStringStartsWith('WEMA-', $payment->reference);
    }

    public function test_verify_fulfills_invoice_after_alatpay_success(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 15000);
        Sanctum::actingAs($user);

        $reference = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk()->json('reference');

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/*' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-alatpay-1',
                    'status' => 'completed',
                    'amount' => 15000,
                    // AlatPay verify returns their own internal orderId here (merchant-prefixed),
                    // NOT our reference — we must not fail the check because of this.
                    'orderId' => $reference,
                ],
            ]),
        ]);

        $this->getJson('/api/payments/verify/'.$reference.'?transactionId=tx-alatpay-1')
            ->assertOk()
            ->assertJsonPath('status', 'successful')
            ->assertJsonPath('method', 'wema');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('tx-alatpay-1', Payment::query()->where('reference', $reference)->value('paystack_reference'));
    }

    public function test_initialize_reuses_pending_wema_payment_for_the_same_invoice(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk();

        $second = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk();

        $this->assertSame($first->json('payment_id'), $second->json('payment_id'));
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_verify_abandons_other_pending_payments_on_the_same_invoice(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        $reference = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk()->json('reference');

        $stale = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'wema',
            'amount' => 7350,
            'status' => 'pending',
            'reference' => 'WEMA-STALEATTEMPT',
            'paystack_reference' => 'tx-stale-1',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/*' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-alatpay-1',
                    'status' => 'completed',
                    'amount' => 7350,
                    'orderId' => 'BELLSUNIVERSITY-internal',
                ],
            ]),
        ]);

        $this->getJson('/api/payments/verify/'.$reference.'?transactionId=tx-alatpay-1')
            ->assertOk()
            ->assertJsonPath('status', 'successful');

        $this->assertSame('abandoned', $stale->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_verify_succeeds_when_alatpay_returns_merchant_prefixed_order_id(): void
    {
        // AlatPay's GET /transactions/{txId} response returns data.orderId as their own
        // merchant-prefixed warehousing ID (e.g. "BELLSUNIVERSITY-abc123"), not the orderId
        // we passed in metadata. Verification must not reject this.
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 20000);
        Sanctum::actingAs($user);

        $reference = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk()->json('reference');

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/*' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-alatpay-2',
                    'status' => 'completed',
                    'amount' => 20000,
                    // Merchant-prefixed orderId — different from our reference
                    'orderId' => 'BELLSUNIVERSITY-tx-alatpay-2-INTERNAL',
                ],
            ]),
        ]);

        $this->getJson('/api/payments/verify/'.$reference.'?transactionId=tx-alatpay-2')
            ->assertOk()
            ->assertJsonPath('status', 'successful');

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_webhook_fulfills_once(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 8000);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'wema',
            'amount' => 8000,
            'status' => 'pending',
            'reference' => 'WEMA-HOOKTEST01',
            'paystack_reference' => 'WEMA-HOOKTEST01',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tx-hook-1',
                    'status' => 'completed',
                    'amount' => 8000,
                    'orderId' => $payment->reference,
                ],
            ]),
        ]);

        $payload = [
            'Value' => [
                'Data' => [
                    'Id' => 'tx-hook-1',
                    'OrderId' => $payment->reference,
                    'Status' => 'completed',
                    'Amount' => 8000,
                ],
                'Status' => true,
                'Message' => 'Success',
            ],
        ];

        $this->postJson('/api/payments/wema/webhook', $payload)->assertOk();
        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->postJson('/api/payments/wema/webhook', $payload)->assertOk();
        $this->assertEquals(1, Payment::query()->where('reference', $payment->reference)->where('status', 'successful')->count());
    }

    public function test_paystack_alias_uses_active_wema_gateway(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->payableInvoice($user, 5000);
        Sanctum::actingAs($user);

        $this->postJson('/api/payments/paystack/initialize', [
            'invoice_id' => $invoice->id,
        ])
            ->assertOk()
            ->assertJsonPath('provider', 'wema');
    }

    public function test_in_flight_paystack_payment_still_verifies_after_switch(): void
    {
        config([
            'services.paystack.secret' => null,
            'services.paystack.allow_demo_fulfill' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->payableInvoice($user, 4000);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 4000,
            'status' => 'pending',
            'reference' => 'PSK-INFLIGHT01',
            'paystack_reference' => 'PSK-INFLIGHT01',
            'purpose' => 'application_fee',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/payments/verify/'.$payment->reference)
            ->assertOk()
            ->assertJsonPath('status', 'successful')
            ->assertJsonPath('method', 'paystack');
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_wallet_topup_uses_wema_when_active(): void
    {
        $user = User::factory()->create(['name' => 'Chioma Okafor', 'status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Chioma',
            'last_name' => 'Okafor',
            'status' => 'active',
            'current_level' => 100,
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 0]);
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/wallet/topup', ['amount' => 2500, 'portal' => 'student'])
            ->assertOk()
            ->assertJsonPath('provider', 'wema')
            ->assertJsonPath('checkout.amount', 2500);

        $this->assertSame('wema', Payment::query()->first()?->method);
        $this->assertStringStartsWith('WEMA-W-', Payment::query()->first()?->reference);
    }

    private function payableInvoice(User $user, float $amount): Invoice
    {
        return Invoice::query()->create([
            'number' => 'INV-WEMA-'.$user->id.'-'.uniqid(),
            'user_id' => $user->id,
            'category' => 'application_fee',
            'amount' => $amount,
            'full_amount' => $amount,
            'balance' => $amount,
            'status' => 'unpaid',
            'wallet_allowed' => false,
        ]);
    }
}
