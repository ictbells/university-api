<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WalletDebitGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_debit_rejects_negative_wallet_balance(): void
    {
        $wallet = $this->walletWithBalance(-4000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient wallet balance.');

        app(WalletService::class)->debit($wallet, 1000, 'Attempted spend', 'finance');
    }

    public function test_debit_rejects_zero_wallet_balance(): void
    {
        $wallet = $this->walletWithBalance(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient wallet balance.');

        app(WalletService::class)->debit($wallet, 500, 'Attempted spend', 'finance');
    }

    public function test_debit_rejects_when_amount_exceeds_positive_balance(): void
    {
        $wallet = $this->walletWithBalance(1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient wallet balance.');

        app(WalletService::class)->debit($wallet, 1000.01, 'Attempted spend', 'finance');
    }

    public function test_debit_succeeds_when_balance_covers_amount(): void
    {
        $wallet = $this->walletWithBalance(5000);

        app(WalletService::class)->debit($wallet, 1500, 'Invoice payment', 'finance', 99);

        $this->assertEquals(3500.0, (float) $wallet->fresh()->balance);
    }

    private function walletWithBalance(float $balance): Wallet
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'status' => 'active',
            'current_level' => 100,
        ]);

        return Wallet::query()->create([
            'student_id' => $student->id,
            'balance' => $balance,
        ]);
    }
}
