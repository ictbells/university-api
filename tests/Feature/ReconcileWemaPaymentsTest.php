<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileWemaPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.wema.public' => 'pk_wema_test',
            'services.wema.secret' => 'sk_wema_test',
            'services.wema.business_id' => 'biz-wema-test',
            'services.wema.merchant_id' => 'merch-wema-test',
            'services.wema.base' => 'https://apibox.alatpay.ng',
            'services.paystack.allow_demo_fulfill' => false,
        ]);
        PaymentGatewaySettings::update(['payment_gateway' => 'wema']);
    }

    public function test_reconcile_fulfills_pending_wema_payment_confirmed_by_alatpay(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 15000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 15000,
            'status' => 'pending',
            'reference' => 'WEMA-RECONCILE01',
            'paystack_reference' => 'tx-reconcile-001',  // AlatPay transactionId stored on verify attempt
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-reconcile-001' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-reconcile-001',
                    'status' => 'completed',
                    'amount' => 15000,
                    'orderId' => 'BELLSUNIVERSITY-internal-id',  // merchant-prefixed, not our reference
                ],
            ]),
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions*' => Http::response([
                'status' => true,
                'data' => [],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_reconcile_accepts_alatpay_amount_including_gateway_fee(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 7000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 7000,
            'status' => 'pending',
            'reference' => 'WEMA-FEECASE01',
            'paystack_reference' => 'tx-fee-001',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-fee-001' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-fee-001',
                    'status' => 'completed',
                    'amount' => 7350,
                    'orderId' => 'BELLSUNIVERSITY-internal-id',
                ],
            ]),
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions*' => Http::response([
                'status' => true,
                'data' => [],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema', ['--id' => [$payment->id]])
            ->assertExitCode(0);

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame(7000.0, (float) $payment->fresh()->amount);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_reconcile_skips_payment_not_yet_confirmed_by_alatpay(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 8000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 8000,
            'status' => 'pending',
            'reference' => 'WEMA-NOTDONE01',
            'paystack_reference' => 'tx-notdone-001',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-notdone-001' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-notdone-001',
                    'status' => 'pending',
                    'amount' => 8000,
                    'orderId' => 'BELLSUNIVERSITY-internal-id',
                ],
            ]),
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions*' => Http::response([
                'status' => true,
                'data' => [],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_reconcile_dry_run_makes_no_changes(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 5000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'WEMA-DRYRUN01',
            'paystack_reference' => 'tx-dryrun-001',
            'purpose' => 'application_fee',
        ]);

        Http::fake(); // no HTTP should be made during dry-run

        $this->artisan('payments:reconcile-wema', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reconcile_fulfills_pending_payment_found_by_order_reference_list(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 3000);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 3000,
            'status' => 'pending',
            'reference' => 'WEMA-NOTXID01',
            'paystack_reference' => 'WEMA-NOTXID01',
            'purpose' => 'application_fee',
            'created_at' => now()->subHour(),
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/transactions/tx-listed-001')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => 'tx-listed-001',
                        'status' => 'completed',
                        'amount' => 3000,
                        'metadata' => ['orderId' => 'WEMA-NOTXID01'],
                    ],
                ]);
            }
            if (str_contains($url, '/alatpaytransaction/api/v1/transactions')) {
                return Http::response([
                    'status' => true,
                    'data' => [[
                        'id' => 'tx-listed-001',
                        'status' => 'completed',
                        'amount' => 3000,
                        'metadata' => ['orderId' => 'WEMA-NOTXID01'],
                    ]],
                    'pagination' => ['totalPages' => 1],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $this->assertSame('successful', Payment::query()->where('reference', 'WEMA-NOTXID01')->value('status'));
        $this->assertSame('paid', $invoice->fresh()->status);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/alatpaytransaction/api/v1/transactions')
                && ($query['search'] ?? null) === 'WEMA-NOTXID01'
                && ($query['businessId'] ?? null) === 'biz-wema-test'
                && ($query['merchantId'] ?? null) === 'merch-wema-test'
                && ! isset($query['startAt']);
        });
    }

    public function test_reconcile_fulfills_when_list_omits_metadata_but_detail_has_order(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 100000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 100000,
            'status' => 'pending',
            'reference' => 'WEMA-LISTOMIT01',
            'paystack_reference' => 'WEMA-LISTOMIT01',
            'purpose' => 'application_fee',
            'created_at' => now()->subHour(),
        ]);

        Http::fake(function (Request $request) use ($invoice) {
            $url = $request->url();
            $meta = '{"orderId":"WEMA-LISTOMIT01","invoice_id":"'.$invoice->id.'","purpose":"application_fee"}';
            if (str_contains($url, '/transactions/tx-detail-meta-001')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => 'tx-detail-meta-001',
                        'status' => 'completed',
                        'amount' => 100350,
                        'feeAmount' => 350,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                        'metadata' => $meta,
                    ],
                ]);
            }
            if (str_contains($url, '/alatpaytransaction/api/v1/transactions')) {
                // List rows often lack usable metadata — only merchant-prefixed orderId.
                return Http::response([
                    'status' => true,
                    'data' => [[
                        'id' => 'tx-detail-meta-001',
                        'status' => 'completed',
                        'amount' => 100350,
                        'feeAmount' => 350,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                    ]],
                    'pagination' => ['totalPages' => 1],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        $this->artisan('payments:reconcile-wema', ['--id' => [$payment->id]])
            ->assertExitCode(0);

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('tx-detail-meta-001', $payment->fresh()->paystack_reference);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_reconcile_fulfills_all_pending_when_metadata_order_id_drifted(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 100000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 100000,
            'status' => 'pending',
            'reference' => 'WEMA-OLDREFERENCE',
            'paystack_reference' => 'WEMA-OLDREFERENCE',
            'purpose' => 'acceptance_fee',
            'created_at' => now()->subHours(2),
        ]);

        Http::fake(function (Request $request) use ($invoice) {
            $url = $request->url();
            $detail = [
                'id' => '3a932c5a-15fc-4bef-940c-5e2bfde317c6',
                'status' => 'success',
                'amount' => 100350,
                'fee' => 350,
                'orderId' => 'BELLSUNIVERSITY-internal',
                'metadata' => [
                    'orderId' => 'WEMA-59NSZ5YAIUTR',
                    'invoice_id' => (string) $invoice->id,
                    'purpose' => 'acceptance_fee',
                ],
            ];

            if (str_contains($url, '/transactions/3a932c5a-15fc-4bef-940c-5e2bfde317c6')) {
                return Http::response(['status' => true, 'data' => $detail]);
            }
            if (str_contains($url, '/alatpaytransaction/api/v1/transactions')) {
                return Http::response([
                    'status' => true,
                    'data' => [[
                        'id' => '3a932c5a-15fc-4bef-940c-5e2bfde317c6',
                        'status' => 'success',
                        'amount' => 100350,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                    ]],
                    'pagination' => ['totalPages' => 1],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $payment->refresh();
        $this->assertSame('successful', $payment->status);
        $this->assertSame('WEMA-59NSZ5YAIUTR', $payment->reference);
        $this->assertSame('3a932c5a-15fc-4bef-940c-5e2bfde317c6', $payment->paystack_reference);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_reconcile_matches_wema_success_by_invoice_id_without_our_order_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 7350);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 7350,
            'status' => 'pending',
            'reference' => 'WEMA-KOPICE8AZR7M',
            'paystack_reference' => 'WEMA-KOPICE8AZR7M',
            'purpose' => 'application_fee',
            'created_at' => now()->subHours(3),
        ]);

        Http::fake(function (Request $request) use ($invoice) {
            $url = $request->url();
            $detail = [
                'Id' => 'alatpay-success-uuid',
                'Status' => 'Success',
                'amount' => 7350,
                'orderId' => 'BELLSUNIVERSITY-internal',
                'metadata' => [
                    'invoice_id' => (string) $invoice->id,
                    'purpose' => 'application_fee',
                ],
            ];

            if (str_contains($url, '/transactions/alatpay-success-uuid')) {
                return Http::response(['status' => true, 'data' => $detail]);
            }
            if (str_contains($url, '/alatpaytransaction/api/v1/transactions')) {
                return Http::response([
                    'status' => true,
                    'data' => [[
                        'Id' => 'alatpay-success-uuid',
                        'Status' => 'Success',
                        'amount' => 7350,
                        'orderId' => 'BELLSUNIVERSITY-internal',
                    ]],
                    'pagination' => ['totalPages' => 1],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_reconcile_abandons_pending_payments_when_invoice_already_paid(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 7350);
        $invoice->update(['status' => 'paid', 'balance' => 0]);
        $stale = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 7350,
            'status' => 'pending',
            'reference' => 'WEMA-ALREADYPAID',
            'paystack_reference' => 'tx-already-paid',
            'purpose' => 'application_fee',
        ]);

        Http::fake();

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $this->assertSame('abandoned', $stale->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reconcile_prints_alatpay_response_when_search_finds_nothing(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 5000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'WEMA-NOMATCH01',
            'paystack_reference' => 'WEMA-NOMATCH01',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions*' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema', ['--id' => [$payment->id]])
            ->expectsOutputToContain('AlatPay returned no transaction for WEMA-NOMATCH01. Success')
            ->expectsOutputToContain('AlatPay HTTP 200')
            ->expectsOutputToContain('payload:')
            ->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_reconcile_matches_customer_metadata_json_when_order_id_drifted(): void
    {
        $user = User::factory()->create(['email' => 'leroyobinna@gmail.com', 'status' => 'active']);
        $invoice = $this->pendingInvoice($user, 7350);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 7350,
            'status' => 'pending',
            'reference' => 'WEMA-9FWZMXZYQBVJ',
            'paystack_reference' => 'WEMA-9FWZMXZYQBVJ',
            'purpose' => 'application_fee',
        ]);

        $detail = [
            'amount' => 7350,
            'feeAmount' => 350,
            'id' => 'e19fc8cf-3b8b-48ff-a740-78e75e5e3ee1',
            'status' => 'completed',
            'orderId' => '400cc86d-d7d3-422b-abaf-64ae46f0b199',
            'customer' => [
                'id' => '53a7a6a0-9dc7-433b-930e-08df0f7e446e',
                'transactionId' => 'e19fc8cf-3b8b-48ff-a740-78e75e5e3ee1',
                'email' => 'leroyobinna@gmail.com',
                'firstName' => 'LEROY',
                'lastName' => 'CHUKWUEBUKA OBINNA',
                'metadata' => '{"orderId":"WEMA-NSMBYQKGECG2","invoice_id":"'.$invoice->id.'","purpose":"application_fee"}',
            ],
        ];

        Http::fake(function (Request $request) use ($detail) {
            $url = $request->url();
            if (str_contains($url, '/transactions/e19fc8cf-3b8b-48ff-a740-78e75e5e3ee1')) {
                return Http::response(['status' => true, 'message' => 'Success', 'data' => $detail]);
            }
            if (str_contains($url, 'search=WEMA-9FWZMXZYQBVJ') || str_contains($url, 'search='.$detail['customer']['metadata'])) {
                return Http::response([
                    'status' => true,
                    'message' => 'Success',
                    'data' => [$detail],
                ]);
            }
            if (str_contains($url, 'search=')) {
                return Http::response([
                    'status' => true,
                    'message' => 'Success',
                    'data' => [$detail],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'Unexpected URL: '.$url], 500);
        });

        $this->artisan('payments:reconcile-wema', ['--id' => [$payment->id]])
            ->assertExitCode(0);

        $payment->refresh();
        $this->assertSame('successful', $payment->status);
        $this->assertSame('WEMA-NSMBYQKGECG2', $payment->reference);
        $this->assertSame('e19fc8cf-3b8b-48ff-a740-78e75e5e3ee1', $payment->paystack_reference);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    private function pendingInvoice(User $user, float $amount): Invoice
    {
        return Invoice::query()->create([
            'number' => 'INV-REC-'.$user->id.'-'.uniqid(),
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
