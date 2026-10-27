<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixDuplicateWalletPaymentCreditsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_duplicates_without_changing_balance(): void
    {
        [$wallet, $payment] = $this->seedDuplicateCredits(5000);

        $this->artisan('payments:fix-duplicate-wallet-credits', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertEquals(10000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(2, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('related_id', $payment->id)
            ->where('type', 'credit')
            ->count());
    }

    public function test_command_removes_extra_credits_and_corrects_balance(): void
    {
        [$wallet, $payment, $keepId, $extraId] = $this->seedDuplicateCredits(5000);

        $this->artisan('payments:fix-duplicate-wallet-credits')
            ->assertSuccessful();

        $this->assertEquals(5000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('related_id', $payment->id)
            ->where('type', 'credit')
            ->count());
        $this->assertNotNull(WalletTransaction::withTrashed()->find($keepId));
        $this->assertTrue(WalletTransaction::withTrashed()->find($extraId)?->trashed());
    }

    public function test_allows_balance_to_go_negative_when_duplicate_was_spent(): void
    {
        [$wallet, $payment, $keepId, $extraId] = $this->seedDuplicateCredits(5000);
        $wallet->update(['balance' => 1000]);

        $this->artisan('payments:fix-duplicate-wallet-credits')
            ->assertSuccessful();

        $this->assertEquals(-4000.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('related_id', $payment->id)
            ->where('type', 'credit')
            ->count());
        $this->assertNotNull(WalletTransaction::withTrashed()->find($keepId));
        $this->assertTrue(WalletTransaction::withTrashed()->find($extraId)?->trashed());
    }

    /**
     * @return array{0: Wallet, 1: Payment, 2: int, 3: int}
     */
    private function seedDuplicateCredits(float $amount): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'status' => 'active',
            'current_level' => 100,
        ]);
        $wallet = Wallet::query()->create([
            'student_id' => $student->id,
            'balance' => $amount * 2,
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => null,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => $amount,
            'status' => 'successful',
            'reference' => 'PSK-W-FIXDUP01',
            'paystack_reference' => 'PSK-W-FIXDUP01',
            'purpose' => 'wallet_topup',
        ]);

        $keep = WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => $amount,
            'reference' => $payment->reference,
            'source_module' => 'payments',
            'related_id' => $payment->id,
            'description' => 'Paystack wallet funding',
        ]);
        $extra = WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => $amount,
            'reference' => $payment->reference,
            'source_module' => 'payments',
            'related_id' => $payment->id,
            'description' => 'Paystack webhook wallet funding',
        ]);

        return [$wallet, $payment, $keep->id, $extra->id];
    }
}
