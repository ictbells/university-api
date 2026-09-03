<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\AlatpayService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ReconcileWemaPayments extends Command
{
    protected $signature = 'payments:reconcile-wema
                            {--dry-run : Show which payments would be reconciled without fulfilling them}
                            {--since= : Only consider payments created on or after this date (Y-m-d)}
                            {--id=* : Specific payment IDs to reconcile}';

    protected $description = 'Re-verify pending Wema/AlatPay payments and fulfill those confirmed by AlatPay.';

    public function handle(AlatpayService $alatpay): int
    {
        $dryRun = $this->option('dry-run');
        $since = $this->option('since');
        $ids = array_filter(array_map('intval', (array) $this->option('id')));

        $staleQuery = Payment::query()
            ->where('method', 'wema')
            ->where('status', 'pending')
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', ['paid', 'cancelled']));
        if ($ids !== []) {
            $staleQuery->whereIn('id', $ids);
        }
        if ($since) {
            $staleQuery->where('created_at', '>=', $since);
        }
        $staleCount = (clone $staleQuery)->count();
        if ($staleCount > 0) {
            if ($dryRun) {
                $this->line("Would abandon {$staleCount} pending payment(s) on invoices that are already settled.");
            } else {
                $staleQuery->update(['status' => 'abandoned']);
                $this->info("Abandoned {$staleCount} pending payment(s) on invoices that are already settled.");
            }
        }

        $query = Payment::query()
            ->where('method', 'wema')
            ->where('status', 'pending')
            ->whereNotNull('paystack_reference')
            ->where('paystack_reference', 'not like', 'WEMA-%') // exclude rows without a real txId
            ->orderBy('created_at');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        $payments = $query->with('invoice')->get();

        if ($payments->isEmpty()) {
            $this->info('No pending Wema payments found.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Found %d pending Wema payment(s)%s.',
            $payments->count(),
            $dryRun ? ' — dry-run, no changes will be made' : ''
        ));

        $fulfilled = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            $txId = (string) $payment->paystack_reference;
            $ref = (string) $payment->reference;

            $label = sprintf(
                'Payment #%d  ref=%s  txId=%s  amount=%.2f',
                $payment->id,
                $ref,
                $txId,
                (float) $payment->amount,
            );

            if ($payment->invoice && ! $payment->invoice->isPayable()) {
                if ($dryRun) {
                    $this->line('  [dry-run] would abandon (invoice already settled): '.$label);
                    $skipped++;
                    continue;
                }
                $payment->update(['status' => 'abandoned']);
                $this->warn('  – invoice already settled, abandoned: '.$label);
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line('  [dry-run] would verify: '.$label);
                $skipped++;
                continue;
            }

            try {
                $result = $alatpay->verify($ref, $txId);

                if ($result->status === 'successful') {
                    $this->info('  ✓ fulfilled: '.$label);
                    $fulfilled++;
                } else {
                    $this->warn('  – still pending after verify: '.$label);
                    $skipped++;
                }
            } catch (RuntimeException $e) {
                // AlatPay explicitly says the payment is not confirmed yet — not an error.
                $this->warn('  – not confirmed by AlatPay: '.$label.' ('.$e->getMessage().')');
                $skipped++;
            } catch (Throwable $e) {
                $this->error('  ✗ failed: '.$label);
                $this->error('      '.$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Done. Fulfilled: %d  |  Skipped/unconfirmed: %d  |  Errors: %d',
            $fulfilled,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
