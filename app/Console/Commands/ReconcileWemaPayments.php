<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\AlatpayService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReconcileWemaPayments extends Command
{
    protected $signature = 'payments:reconcile-wema
                            {--dry-run : Show which payments would be reconciled without fulfilling them}
                            {--since= : Only consider payments created on or after this date (Y-m-d)}
                            {--days=30 : Date-window fallback in days if metadata search returns nothing}
                            {--id=* : Specific payment IDs to reconcile}
                            {--transaction-id= : AlatPay transaction id (use with a single --id when search cannot find it)}
                            {--trace-lookup : Print the full AlatPay JSON body on failures}';

    protected $description = 'Re-verify pending Wema/AlatPay payments via metadata search and fulfill those AlatPay confirms.';

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

        $alatpay->setListLookbackDays($days);

        $fulfilled = 0;
        $skipped = 0;
        $failed = 0;
        $noTxId = 0;

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
                $verifyTxId ?: ($txId !== '' ? $txId : 'search'),
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
                $this->line('  [dry-run] would search/verify: '.$label);
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
                    $this->writeAlatpayLookup($alatpay, $traceLookup);
                    $skipped++;
                }
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                if (
                    str_contains($message, 'has not returned a transaction ID')
                    || str_contains($message, 'returned no transaction')
                    || str_contains($message, 'AlatPay HTTP')
                    || str_contains($message, 'AlatPay was not queried')
                ) {
                    $noTxId++;
                }
                $this->warn('  – not confirmed by AlatPay: '.$label.' ('.$message.')');
                $this->writeAlatpayLookup($alatpay, $traceLookup);
                $skipped++;
            } catch (Throwable $e) {
                $this->error('  ✗ failed: '.$label);
                $this->error('      '.$e->getMessage());
                $this->writeAlatpayLookup($alatpay, $traceLookup);
                $failed++;
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
            $this->comment('Wema/AlatPay payloads were written to storage/logs/alatpay-lookup.log');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function writeAlatpayLookup(AlatpayService $alatpay, bool $fullBody): void
    {
        $lookup = $alatpay->lastGatewayLookup();
        if (! is_array($lookup) || $lookup === []) {
            return;
        }

        $this->line(sprintf(
            '      AlatPay HTTP %s  ok=%s  rows=%d',
            $lookup['http_status'] ?? 'n/a',
            ($lookup['ok'] ?? false) ? 'true' : 'false',
            $lookup['row_count'] ?? 0,
        ));
        if (! empty($lookup['url'])) {
            $this->line('      '.$lookup['url']);
        }

        $body = is_array($lookup['body'] ?? null) ? $lookup['body'] : [];
        $alatpayMessage = $body['message'] ?? $body['Message'] ?? $body['statusReason'] ?? null;
        if (is_string($alatpayMessage) && trim($alatpayMessage) !== '') {
            $this->line('      '.$alatpayMessage);
        }

        $toPrint = $body;
        if (isset($toPrint['data']) && is_array($toPrint['data']) && array_is_list($toPrint['data']) && $toPrint['data'] !== []) {
            $toPrint['data'] = [$toPrint['data'][0]];
            if (($lookup['row_count'] ?? 0) > 1) {
                $toPrint['_showing'] = 'first row only';
            }
        }
        if ($json = json_encode($toPrint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) {
            $this->line('      payload: '.Str::limit($json, $fullBody ? 20000 : 4000));
        }
    }
}
