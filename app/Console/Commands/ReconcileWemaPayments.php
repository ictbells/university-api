<?php

namespace App\Console\Commands;

use App\Mail\WemaReconcileReportMail;
use App\Models\Payment;
use App\Services\AlatpayService;
use App\Services\WemaReconcileReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
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
                            {--trace-lookup : Print full AlatPay “no tx id” messages}
                            {--no-report : Skip Excel report generation and email}
                            {--no-email : Write the Excel report but do not email it}';

    protected $description = 'Re-verify all pending Wema/AlatPay payments, fulfill those confirmed by AlatPay, and email an Excel sent-vs-returned report.';

    public const REPORT_EMAIL = 'oluropoadewale@gmail.com';

    public function handle(AlatpayService $alatpay, WemaReconcileReportService $reportService): int
    {
        $dryRun = $this->option('dry-run');
        $since = $this->option('since');
        $days = max(1, (int) $this->option('days'));
        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        $forcedTxId = trim((string) $this->option('transaction-id'));
        $traceLookup = (bool) $this->option('trace-lookup');
        $wantReport = ! (bool) $this->option('no-report');

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

        $payments = $query->with(['invoice', 'user'])->get();

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
        $outcomes = [];
        foreach ($payments as $payment) {
            $outcomes[$payment->id] = ['action' => 'unconfirmed / still pending', 'message' => ''];
        }

        $sentById = [];
        foreach ($payments as $payment) {
            $sentById[$payment->id] = [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'invoice_number' => $payment->invoice?->number,
                'payer_email' => $payment->user?->email,
                'purpose' => $payment->purpose,
                'amount_sent' => $payment->amount,
                'our_order_id' => (string) $payment->reference,
                'stored_gateway_id' => (string) $payment->paystack_reference,
                'portal_status_before' => (string) $payment->status,
                'payment_created_at' => optional($payment->created_at)?->toDateTimeString(),
            ];
        }

        // Prefetch whenever we will fulfill or build a report (including dry-run).
        $needsListLookup = $forcedTxId === '' && (! $dryRun || $wantReport);
        $prefetched = 0;
        $matchesById = [];
        $unmatchedWema = [];

        try {
            if ($needsListLookup) {
                $earliest = $payments->min(fn (Payment $payment) => $payment->created_at);
                $fromPayment = $earliest
                    ? Carbon::parse($earliest)->subDay()
                    : now()->subDays($days);
                $from = $fromPayment->lt(now()->subDays($days))
                    ? $fromPayment
                    : now()->subDays($days);
                $alatpay->beginCompletedTransactionLookup($from, now()->addDay());
                $prefetched = $alatpay->completedTransactionCount();
                $this->line(sprintf(
                    'Prefetched %d AlatPay transaction(s) since %s.',
                    $prefetched,
                    $from->toDateString()
                ));
                if ($prefetched === 0) {
                    $this->warn('AlatPay returned no transactions for that window. Check WEMA_ALATPAY_BUSINESS_ID / API keys, or widen --days.');
                }
            }

            if ($wantReport) {
                foreach ($payments as $payment) {
                    $matchesById[$payment->id] = $alatpay->matchCompletedTransactionForReport(
                        $payment,
                        $forcedTxId !== '' ? $forcedTxId : null,
                    );
                }
                $unmatchedWema = $alatpay->unmatchedCompletedTransactionsForReport($payments);
            }

            if ($needsListLookup && ! $dryRun) {
                $applied = $alatpay->applyCompletedTransactionsToPending($payments);
                $fulfilled += $applied['fulfilled'];
                if ($applied['fulfilled'] > 0) {
                    $this->info(sprintf('  ✓ matched %d pending payment(s) from AlatPay completed charges.', $applied['fulfilled']));
                    foreach ($payments as $payment) {
                        if ($payment->fresh()?->status === 'successful') {
                            $outcomes[$payment->id] = ['action' => 'fulfilled', 'message' => ''];
                        }
                    }
                }
                $payments = $applied['remaining'];
            }

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
                        $outcomes[$payment->id] = ['action' => 'would abandon (invoice settled)', 'message' => ''];
                        $skipped++;

                        continue;
                    }
                    $payment->update(['status' => 'abandoned']);
                    $this->warn('  – invoice already settled, abandoned: '.$label);
                    $outcomes[$payment->id] = ['action' => 'abandoned', 'message' => 'Invoice already settled'];
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line('  [dry-run] would verify: '.$label);
                    $outcomes[$payment->id] = ['action' => 'dry-run (no changes)', 'message' => ''];
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
                        $outcomes[$payment->id] = ['action' => 'fulfilled', 'message' => ''];
                        $fulfilled++;
                    } else {
                        $this->warn('  – still pending after verify: '.$label);
                        $outcomes[$payment->id] = ['action' => 'still pending after verify', 'message' => ''];
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
                    $outcomes[$payment->id] = ['action' => 'not confirmed by AlatPay', 'message' => $message];
                    $skipped++;
                } catch (Throwable $e) {
                    $this->error('  ✗ failed: '.$label);
                    $this->error('      '.$e->getMessage());
                    $outcomes[$payment->id] = ['action' => 'error', 'message' => $e->getMessage()];
                    $failed++;
                }
            }
        } finally {
            if ($wantReport) {
                $this->writeAndMaybeEmailReport(
                    $alatpay,
                    $reportService,
                    $sentById,
                    $matchesById,
                    $unmatchedWema,
                    $outcomes,
                    [
                        'generated_at' => now()->timezone('Africa/Lagos')->toDateTimeString(),
                        'days' => $days,
                        'dry_run' => $dryRun,
                        'fulfilled' => $fulfilled,
                        'skipped' => $skipped,
                        'no_tx_id' => $noTxId,
                        'failed' => $failed,
                        'prefetched' => $prefetched,
                        'pending_count' => count($sentById),
                        'unmatched_count' => count($unmatchedWema),
                    ],
                );
            }

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

    /**
     * @param  array<int, array<string, mixed>>  $sentById
     * @param  array<int, array{match_by: string, transaction: array<string, mixed>|null}>  $matchesById
     * @param  list<array<string, mixed>>  $unmatchedWema
     * @param  array<int, array{action: string, message: string}>  $outcomes
     * @param  array<string, mixed>  $summary
     */
    private function writeAndMaybeEmailReport(
        AlatpayService $alatpay,
        WemaReconcileReportService $reportService,
        array $sentById,
        array $matchesById,
        array $unmatchedWema,
        array $outcomes,
        array $summary,
    ): void {
        $pendingRows = [];
        foreach ($sentById as $id => $sent) {
            $match = $matchesById[$id] ?? ['match_by' => 'none', 'transaction' => null];
            $wema = $alatpay->flattenTransactionForReport($match['transaction'] ?? null);
            $fresh = Payment::query()->find($id);
            $ourOrder = (string) ($sent['our_order_id'] ?? '');
            $invoiceId = (string) ($sent['invoice_id'] ?? '');
            $amountSent = (float) ($sent['amount_sent'] ?? 0);
            $wemaAmount = is_numeric($wema['wema_amount'] ?? null) ? (float) $wema['wema_amount'] : null;

            $pendingRows[] = array_merge($sent, $wema, [
                'match_by' => $match['match_by'] ?? 'none',
                'order_id_match' => $this->yesNo(
                    $ourOrder !== '' && (
                        strcasecmp($ourOrder, (string) $wema['wema_metadata_order_id']) === 0
                        || strcasecmp($ourOrder, (string) $wema['wema_order_id']) === 0
                    )
                ),
                'invoice_id_match' => $this->yesNo(
                    $invoiceId !== '' && $invoiceId === (string) $wema['wema_metadata_invoice_id']
                ),
                'amount_match' => $wemaAmount === null
                    ? ''
                    : $this->yesNo(abs($wemaAmount - $amountSent) <= 0.5 || $wemaAmount >= ($amountSent - 0.5)),
                'reconcile_result' => $outcomes[$id]['action'] ?? '',
                'portal_status_after' => $fresh?->status ?? ($sent['portal_status_before'] ?? ''),
                'notes' => $outcomes[$id]['message'] ?? '',
            ]);
        }

        $unmatchedRows = array_map(
            fn (array $row) => $alatpay->flattenTransactionForReport($row),
            $unmatchedWema,
        );

        $filename = 'wema-reconcile-'.now()->timezone('Africa/Lagos')->format('Ymd-His').'.xlsx';
        $path = storage_path('app/wema-reconcile/'.$filename);

        try {
            $reportService->write($pendingRows, $unmatchedRows, $summary, $path);
            $this->info('Excel report: '.$path);
        } catch (Throwable $e) {
            $this->error('Could not write Excel report: '.$e->getMessage());

            return;
        }

        if ($this->option('no-email')) {
            $this->comment('Skipped email (--no-email).');

            return;
        }

        try {
            Mail::to(self::REPORT_EMAIL)->send(new WemaReconcileReportMail($summary, $path, $filename));
            $this->info('Report emailed to '.self::REPORT_EMAIL);
        } catch (Throwable $e) {
            $this->error('Could not email report to '.self::REPORT_EMAIL.': '.$e->getMessage());
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
