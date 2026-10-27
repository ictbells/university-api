<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Support\FinanceExportProfiler;
use App\Support\StudentFinanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FinanceExportHydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_paid_sum_matches_settlement_without_loading_payments(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'status' => 'active',
            'current_level' => 100,
        ]);
        Wallet::query()->create(['student_id' => $student->id, 'balance' => 0]);

        $invoice = Invoice::query()->create([
            'number' => 'INV-HYDRATE-1',
            'user_id' => $user->id,
            'student_id' => $student->id,
            'category' => 'hostel',
            'amount' => 5000,
            'full_amount' => 5000,
            'balance' => 2000,
            'status' => 'partial',
            'wallet_allowed' => true,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'wallet',
            'amount' => 3000,
            'status' => 'successful',
            'reference' => 'WALLET-HYDRATE-1',
            'purpose' => 'hostel',
        ]);
        // Must not count toward invoice paid_sum.
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'method' => 'paystack',
            'amount' => 9999,
            'status' => 'successful',
            'reference' => 'PSK-W-HYDRATE-1',
            'purpose' => 'wallet_topup',
        ]);

        $controller = app(\App\Http\Controllers\FinanceController::class);
        $method = new \ReflectionMethod($controller, 'hydrateFinanceSummaries');
        $method->setAccessible(true);
        $method->invoke($controller, collect([$student]));

        $summary = $student->getAttribute('finance_summary');
        $this->assertIsArray($summary);
        $this->assertEquals(3000.0, (float) $summary['paid']);

        $direct = StudentFinanceStatus::summarize($student, collect([$invoice->fresh()->load('payments')]));
        $this->assertEquals($direct['paid'], $summary['paid']);
        $this->assertEquals($direct['outstanding'], $summary['outstanding']);
    }

    public function test_profiler_logs_stage_metrics_without_pii_when_enabled(): void
    {
        Log::spy();
        config(['sis.finance_export_profile' => true]);

        $profiler = new FinanceExportProfiler(true);
        $profiler->mark('student_count', ['count' => 12]);
        $profiler->mark('hydrate.invoice_lookup', ['students' => 12, 'invoices' => 40, 'ms' => 15.2]);
        $profiler->flush('csv', 12);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            if ($message !== 'finance_export.profile') {
                return false;
            }
            $encoded = json_encode($context);
            $this->assertStringNotContainsString('matric', strtolower($encoded ?: ''));
            $this->assertStringNotContainsString('email', strtolower($encoded ?: ''));
            $this->assertSame('csv', $context['format']);
            $this->assertSame(12, $context['student_count']);
            $this->assertNotEmpty($context['stages']);

            return true;
        });
    }

    public function test_csv_chunk_config_defaults_to_250(): void
    {
        $this->assertSame(250, (int) config('sis.finance_export_csv_chunk'));
    }
}
