<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileWemaPayments;
use App\Mail\WemaReconcileReportMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
            'services.wema.base' => 'https://apibox.alatpay.ng',
            'services.paystack.allow_demo_fulfill' => false,
        ]);
        PaymentGatewaySettings::update(['payment_gateway' => 'wema']);
        Mail::fake();
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

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions*' => Http::response([
                'status' => true,
                'data' => [],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('pending', $payment->fresh()->status);
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

    public function test_reconcile_emails_excel_comparing_what_we_sent_and_what_wema_returned(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email' => 'payer@example.com']);
        $invoice = $this->pendingInvoice($user, 100000);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 100000,
            'status' => 'pending',
            'reference' => 'WEMA-59NSZ5YAIUTR',
            'paystack_reference' => 'WEMA-59NSZ5YAIUTR',
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

        Mail::assertSent(WemaReconcileReportMail::class, function (WemaReconcileReportMail $mail) {
            return $mail->hasTo(ReconcileWemaPayments::REPORT_EMAIL)
                && is_file($mail->path)
                && str_ends_with($mail->filename, '.xlsx');
        });

        $files = glob(storage_path('app/wema-reconcile/*.xlsx')) ?: [];
        $this->assertNotEmpty($files, 'Expected an Excel report in storage/app/wema-reconcile');
        $sheet = IOFactory::load(end($files))->getSheetByName('Pending payments');
        $this->assertNotNull($sheet);
        $this->assertSame('What we sent to Wema', $sheet->getCell('A1')->getValue());
        $this->assertSame('What Wema returned', $sheet->getCell('K1')->getValue());
        $this->assertSame('WEMA-59NSZ5YAIUTR', $sheet->getCell('G3')->getValue());
        $this->assertSame('3a932c5a-15fc-4bef-940c-5e2bfde317c6', $sheet->getCell('K3')->getValue());
        $this->assertSame('success', $sheet->getCell('L3')->getValue());
        $this->assertEquals(100350, $sheet->getCell('M3')->getValue());
        $this->assertEquals(350, $sheet->getCell('N3')->getValue());
        $this->assertSame('BELLSUNIVERSITY-internal', $sheet->getCell('O3')->getValue());
        $this->assertSame('WEMA-59NSZ5YAIUTR', $sheet->getCell('P3')->getValue());
        $this->assertSame((string) $invoice->id, (string) $sheet->getCell('Q3')->getValue());
        $this->assertSame('Yes', $sheet->getCell('V3')->getValue());
        $this->assertSame('fulfilled', $sheet->getCell('Y3')->getValue());
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_reconcile_skips_email_when_no_email_flag_is_set(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 15000);
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 15000,
            'status' => 'pending',
            'reference' => 'WEMA-NOEMAIL001',
            'paystack_reference' => 'tx-no-email-001',
            'purpose' => 'application_fee',
        ]);

        Http::fake([
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-no-email-001' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tx-no-email-001',
                    'status' => 'completed',
                    'amount' => 15000,
                    'orderId' => 'BELLSUNIVERSITY-internal-id',
                ],
            ]),
            'https://apibox.alatpay.ng/alatpaytransaction/api/v1/transactions*' => Http::response([
                'status' => true,
                'data' => [],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema', ['--no-email' => true])
            ->assertExitCode(0);

        Mail::assertNothingSent();
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
