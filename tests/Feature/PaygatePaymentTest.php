<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaygatePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paygate.merchant_id' => 'BELLSMERCH',
            'services.paygate.secret' => 'paygate_secret',
            'services.paygate.base' => 'https://thirdparty.paygate.upperlink.ng',
            'services.paystack.allow_demo_fulfill' => false,
        ]);
        PaymentGatewaySettings::update(['payment_gateway' => 'paygate']);
    }

    public function test_initialize_returns_paygate_checkout_url(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada@example.com',
            'phone' => '08031234567',
            'status' => 'active',
        ]);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/payment' => Http::response([
                'code' => '200',
                'description' => 'Successful',
                'data' => [
                    'payGateRef' => 'UPG-TESTREF0001',
                    'amount' => '7350',
                    'checkOutUrl' => 'https://checkout.paygate.upperlink.ng/payment/link/abc/UPG-TESTREF0001',
                ],
            ]),
        ]);

        $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('provider', 'paygate')
            ->assertJsonPath('demo', false)
            ->assertJsonPath('authorization_url', 'https://checkout.paygate.upperlink.ng/payment/link/abc/UPG-TESTREF0001');

        $payment = Payment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame('paygate', $payment->method);
        $this->assertSame('pending', $payment->status);
        $this->assertStringStartsWith('UPG-', $payment->reference);
    }

    public function test_initialize_sends_basic_auth_header_even_when_username_blank(): void
    {
        config([
            'services.paygate.username' => '',
            'services.paygate.password' => '',
        ]);

        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada@example.com',
            'phone' => '08031234567',
            'status' => 'active',
        ]);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/payment' => Http::response([
                'code' => '200',
                'description' => 'Successful',
                'data' => [
                    'payGateRef' => 'UPG-TESTREF0001',
                    'amount' => '7350',
                    'checkOutUrl' => 'https://checkout.paygate.upperlink.ng/payment/link/abc/UPG-TESTREF0001',
                ],
            ]),
        ]);

        $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic '.base64_encode(':'))
                && str_contains($request->url(), '/transaction/payment');
        });
    }

    public function test_initialize_sends_basic_auth_when_username_and_password_set(): void
    {
        config([
            'services.paygate.username' => 'paygate_user',
            'services.paygate.password' => 'paygate_pass',
        ]);

        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada@example.com',
            'phone' => '08031234567',
            'status' => 'active',
        ]);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/payment' => Http::response([
                'code' => '200',
                'description' => 'Successful',
                'data' => [
                    'payGateRef' => 'UPG-TESTREF0001',
                    'amount' => '7350',
                    'checkOutUrl' => 'https://checkout.paygate.upperlink.ng/payment/link/abc/UPG-TESTREF0001',
                ],
            ]),
        ]);

        $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic '.base64_encode('paygate_user:paygate_pass'))
                && str_contains($request->url(), '/transaction/payment');
        });
    }

    public function test_initialize_surfaces_paygate_description_when_checkout_url_missing(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada@example.com',
            'status' => 'active',
        ]);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/payment' => Http::response([
                'code' => '401',
                'description' => 'Unauthorized',
                'data' => null,
            ]),
        ]);

        $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unauthorized (code 401)');
    }

    public function test_initialize_rotates_reference_when_pending_payment_reused(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada@example.com',
            'phone' => '08031234567',
            'status' => 'active',
        ]);
        $invoice = $this->payableInvoice($user, 7350);
        $existing = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'paygate',
            'amount' => 7350,
            'status' => 'pending',
            'reference' => 'UPG-OLDREF000001',
            'paystack_reference' => 'UPG-OLDREF000001',
            'purpose' => 'application_fee',
        ]);
        Sanctum::actingAs($user);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/payment' => Http::response([
                'code' => '200',
                'description' => 'Successful',
                'data' => [
                    'payGateRef' => 'UPG-NEWREF000001',
                    'checkOutUrl' => 'https://checkout.paygate.upperlink.ng/payment/link/abc/new',
                ],
            ]),
        ]);

        $reference = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk()->json('reference');

        $this->assertNotSame('UPG-OLDREF000001', $reference);
        $this->assertSame($existing->id, Payment::query()->where('invoice_id', $invoice->id)->value('id'));
        $this->assertSame($reference, $existing->fresh()->reference);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['payGateRef'] ?? null) !== 'UPG-OLDREF000001'
                && str_contains($request->url(), '/transaction/payment');
        });
    }

    public function test_initialize_retries_with_new_ref_on_paygate_409(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Okoye',
            'email' => 'ada@example.com',
            'phone' => '08031234567',
            'status' => 'active',
        ]);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        Http::fake(function ($request) {
            static $calls = 0;
            $calls++;
            if ($calls === 1) {
                return Http::response([
                    'code' => '409',
                    'description' => 'Transaction ref already exist for this merchant',
                    'data' => null,
                ]);
            }

            return Http::response([
                'code' => '200',
                'description' => 'Successful',
                'data' => [
                    'payGateRef' => 'UPG-RETRYREF0001',
                    'checkOutUrl' => 'https://checkout.paygate.upperlink.ng/payment/link/abc/retry',
                ],
            ]);
        });

        $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('authorization_url', 'https://checkout.paygate.upperlink.ng/payment/link/abc/retry');

        Http::assertSentCount(2);
    }

    public function test_verify_fulfills_invoice_after_paygate_success(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okoye', 'status' => 'active']);
        $invoice = $this->payableInvoice($user, 7350);
        Sanctum::actingAs($user);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/payment' => Http::response([
                'code' => '200',
                'data' => [
                    'payGateRef' => 'UPG-VERIFYREF01',
                    'checkOutUrl' => 'https://checkout.paygate.upperlink.ng/payment/link/x/y',
                ],
            ]),
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/query*' => Http::response([
                'amount' => '7350',
                'payGateRef' => 'UPG-VERIFYREF01',
                'transactionId' => 'UPG-tx-1',
                'transactionStatus' => '00',
                'transactionCompleted' => 'SUCCESSFUL',
            ]),
        ]);

        $reference = $this->postJson('/api/payments/initialize', [
            'invoice_id' => $invoice->id,
            'portal' => 'student',
        ])->assertOk()->json('reference');

        // Align stored reference with the query stub when initialize reused/changed ref.
        Payment::query()->where('reference', $reference)->update([
            'reference' => 'UPG-VERIFYREF01',
            'paystack_reference' => 'UPG-VERIFYREF01',
        ]);

        $this->getJson('/api/payments/verify/UPG-VERIFYREF01')
            ->assertOk()
            ->assertJsonPath('status', 'successful')
            ->assertJsonPath('method', 'paygate');

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_webhook_fulfills_with_valid_hash(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->payableInvoice($user, 5000);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'paygate',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'UPG-HOOKTEST01',
            'paystack_reference' => 'UPG-HOOKTEST01',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://thirdparty.paygate.upperlink.ng/api/v1/client/integration/transaction/query*' => Http::response([
                'amount' => '5000',
                'payGateRef' => 'UPG-HOOKTEST01',
                'transactionId' => 'UPG-tx-hook-1',
                'transactionStatus' => '00',
                'transactionCompleted' => 'SUCCESSFUL',
            ]),
        ]);

        $secret = 'paygate_secret';
        $merchantId = 'BELLSMERCH';
        $payGateRef = 'UPG-HOOKTEST01';
        $transactionId = 'UPG-tx-hook-1';
        $hash = strtoupper(hash('sha512', $secret.$merchantId.$payGateRef.$transactionId));

        $this->postJson('/api/payments/paygate/webhook', [
            'amount' => '5000',
            'merchantId' => $merchantId,
            'payGateRef' => $payGateRef,
            'transactionId' => $transactionId,
            'transactionCompleted' => 'SUCCESSFUL',
            'transactionStatus' => '00',
            'hash' => $hash,
        ])
            ->assertOk()
            ->assertJsonPath('code', '00');

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    private function payableInvoice(User $user, float $amount): Invoice
    {
        return Invoice::query()->create([
            'number' => 'INV-UPG-'.$user->id.'-'.uniqid(),
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
