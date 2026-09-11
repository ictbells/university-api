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
                            {--days=30 : Minimum AlatPay lookback window in days when matching pending payments}
                            {--id=* : Specific payment IDs to reconcile}
                            {--transaction-id= : AlatPay transaction id (use with a single --id when list lookup cannot find it)}
                            {--trace-lookup : Print full AlatPay “no tx id” messages}';

    protected $description = 'Re-verify all pending Wema/AlatPay payments and fulfill those confirmed by AlatPay.';

    public function handle(AlatpayService $alatpay): int
    {
        $dryRun = $this->option('dry-run');
        $since = $this->option('since');
        $days = max(1, (int) $this->option('days'));
        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        $forcedTxId = trim((string) $this->option('transaction-id'));
        $traceLookup = (bool) $this->option('trace-lookup');

        if ($forcedTxId !== '' && count($ids) !== 1) {
            $this->error('Use --transaction-id together with exactly one --id=<paymentId>.');

            return self::FAILURE;
        }

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
        $noTxId = 0;

        // Always prefetch for bulk runs so drifted metadata.orderId can still match via invoice_id.
        $needsListLookup = ! $dryRun && $forcedTxId === '';
        if ($needsListLookup) {
            $earliest = $payments->min(fn (Payment $payment) => $payment->created_at);
            $fromPayment = $earliest
                ? \Carbon\Carbon::parse($earliest)->subDay()
                : now()->subDays($days);
            $from = $fromPayment->lt(now()->subDays($days))
                ? $fromPayment
                : now()->subDays($days);
            $alatpay->beginCompletedTransactionLookup($from, now()->addDay());
            $this->line(sprintf(
                'Prefetched AlatPay completed transactions since %s.',
                $from->toDateString()
            ));
        }

        try {
            foreach ($payments as $payment) {
                $txId = (string) $payment->paystack_reference;
                $ref = (string) $payment->reference;
                $verifyTxId = $forcedTxId !== ''
                    ? $forcedTxId
                    : (str_starts_with($txId, 'WEMA-') ? null : ($txId !== '' ? $txId : null));

                $label = sprintf(
                    'Payment #%d  ref=%s  txId=%s  amount=%.2f',
                    $payment->id,
                    $ref,
                    $verifyTxId ?: ($txId !== '' ? $txId : 'list-lookup'),
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
                    $result = $alatpay->reconcilePayment(
                        $payment,
                        $forcedTxId !== '' ? $forcedTxId : null,
                    );

                    if ($result->status === 'successful') {
                        $this->info('  ✓ fulfilled: '.$label);
                        $fulfilled++;
                    } else {
                        $this->warn('  – still pending after verify: '.$label);
                        $skipped++;
                    }
                } catch (RuntimeException $e) {
                    $message = $e->getMessage();
                    if (str_contains($message, 'has not returned a transaction ID')) {
                        $noTxId++;
                    }
                    if ($traceLookup) {
                        $this->line('      '.$message);
                    }
                    $this->warn('  – not confirmed by AlatPay: '.$label.' ('.$message.')');
                    $skipped++;
                } catch (Throwable $e) {
                    $this->error('  ✗ failed: '.$label);
                    $this->error('      '.$e->getMessage());
                    $failed++;
                }
            }
        } finally {
            if ($needsListLookup) {
                $alatpay->endCompletedTransactionLookup();
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Done. Fulfilled: %d  |  Skipped/unconfirmed: %d  |  No AlatPay tx id found: %d  |  Errors: %d',
            $fulfilled,
            $skipped,
            $noTxId,
            $failed,
        ));
        if ($noTxId > 0) {
            $this->comment(
                'Tip: many of those are abandoned checkouts (student opened pay, never finished). '
                .'If Wema dashboard shows a completed charge, copy its transaction id and run: '
                .'php artisan payments:reconcile-wema --id=<paymentId> --transaction-id=<alatpayTxId>'
            );
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
