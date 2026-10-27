<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PaymentFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentFulfillmentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_topup_fulfill_credits_once_when_verify_and_webhook_both_run(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'status' => 'active',
            'current_level' => 100,
        ]);
        $wallet = Wallet::query()->create(['student_id' => $student->id, 'balance' => 0]);

        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'PSK-W-DUPTEST01',
            'paystack_reference' => 'PSK-W-DUPTEST01',
            'purpose' => 'wallet_topup',
        ]);

        $fulfillment = app(PaymentFulfillmentService::class);
        $first = $fulfillment->fulfill($payment, 'Paystack');
        $second = $fulfillment->fulfill($payment->fresh(), 'Paystack webhook');

        $this->assertSame('successful', $first->status);
        $this->assertSame('successful', $second->status);
        $this->assertSame($first->receipt_no, $second->receipt_no);
        $this->assertEquals(5000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where('source_module', 'payments')
            ->where('related_id', $payment->id)
            ->count());
        $this->assertSame(1, Payment::query()->where('reference', $payment->reference)->where('status', 'successful')->count());
    }

    public function test_wallet_credit_skips_duplicate_payment_related_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Chioma',
            'last_name' => 'Okafor',
            'status' => 'active',
            'current_level' => 100,
        ]);
        $wallet = Wallet::query()->create(['student_id' => $student->id, 'balance' => 0]);
        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'wema',
            'amount' => 2500,
            'status' => 'successful',
            'reference' => 'WEMA-W-GUARD01',
            'paystack_reference' => 'WEMA-W-GUARD01',
            'purpose' => 'wallet_topup',
        ]);

        $wallets = app(\App\Services\WalletService::class);
        $wallets->credit($wallet, 2500, 'Paystack wallet funding', 'payments', $payment->id, $payment->reference);
        $wallets->credit($wallet->fresh(), 2500, 'Paystack webhook wallet funding', 'payments', $payment->id, $payment->reference);

        $this->assertEquals(2500.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('related_id', $payment->id)
            ->count());
    }
}
