<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'services.wema.base' => 'https://api.alatpay.ng',
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
            'https://api.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-reconcile-001' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-reconcile-001',
                    'status' => 'completed',
                    'amount' => 15000,
                    'orderId' => 'BELLSUNIVERSITY-internal-id',  // merchant-prefixed, not our reference
                ],
            ]),
        ]);

        $this->artisan('payments:reconcile-wema')
            ->assertExitCode(0);

        $this->assertSame('successful', $payment->fresh()->status);
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
            'https://api.alatpay.ng/alatpaytransaction/api/v1/transactions/tx-notdone-001' => Http::response([
                'status' => true,
                'message' => 'Success',
                'data' => [
                    'id' => 'tx-notdone-001',
                    'status' => 'pending',
                    'amount' => 8000,
                    'orderId' => 'BELLSUNIVERSITY-internal-id',
                ],
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

    public function test_reconcile_ignores_payments_without_real_transaction_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $invoice = $this->pendingInvoice($user, 3000);
        // paystack_reference still holds our own WEMA- reference (transactionId never captured)
        Payment::query()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => 'wema',
            'amount' => 3000,
            'status' => 'pending',
            'reference' => 'WEMA-NOTXID01',
            'paystack_reference' => 'WEMA-NOTXID01',
            'purpose' => 'application_fee',
        ]);

        Http::fake();

        $this->artisan('payments:reconcile-wema')
            ->expectsOutput('No pending Wema payments found.')
            ->assertExitCode(0);

        Http::assertNothingSent();
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
