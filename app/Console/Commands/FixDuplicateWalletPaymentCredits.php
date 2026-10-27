<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FixDuplicateWalletPaymentCredits extends Command
{
    protected $signature = 'payments:fix-duplicate-wallet-credits
                            {--dry-run : List duplicate credits without changing balances}
                            {--payment=* : Limit to these payment IDs (related_id)}
                            {--wallet=* : Limit to these wallet IDs}';

    protected $description = 'Remove duplicate wallet credits caused by the same payment being fulfilled twice (verify + webhook).';

    public function handle(AuditWriter $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $paymentIds = array_values(array_filter(array_map('intval', (array) $this->option('payment'))));
        $walletIds = array_values(array_filter(array_map('intval', (array) $this->option('wallet'))));

        $groups = WalletTransaction::query()
            ->select('wallet_id', 'related_id')
            ->selectRaw('COUNT(*) as credit_count')
            ->selectRaw('SUM(amount) as credit_sum')
            ->where('type', 'credit')
            ->where('source_module', 'payments')
            ->whereNotNull('related_id')
            ->when($paymentIds !== [], fn ($q) => $q->whereIn('related_id', $paymentIds))
            ->when($walletIds !== [], fn ($q) => $q->whereIn('wallet_id', $walletIds))
            ->groupBy('wallet_id', 'related_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('wallet_id')
            ->orderBy('related_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate payment wallet credits found.');

            return self::SUCCESS;
        }

        $this->line(($dryRun ? 'Would fix' : 'Fixing').' '.$groups->count().' payment(s) with duplicate wallet credits.');

        $fixed = 0;
        $skipped = 0;
        $reversedAmount = 0.0;
        $rows = [];

        foreach ($groups as $group) {
            $walletId = (int) $group->wallet_id;
            $paymentId = (int) $group->related_id;

            $credits = WalletTransaction::query()
                ->where('wallet_id', $walletId)
                ->where('type', 'credit')
                ->where('source_module', 'payments')
                ->where('related_id', $paymentId)
                ->orderBy('id')
                ->get();

            $keep = $credits->first();
            $extras = $credits->slice(1)->values();
            if (! $keep || $extras->isEmpty()) {
                continue;
            }

            $extraAmount = round((float) $extras->sum(fn ($tx) => (float) $tx->amount), 2);
            $payment = Payment::query()->find($paymentId);
            $reference = (string) ($keep->reference ?: $payment?->reference ?: '');
            $wallet = Wallet::query()->find($walletId);
            $balance = $wallet ? (float) $wallet->balance : null;

            $row = [
                'wallet_id' => $walletId,
                'payment_id' => $paymentId,
                'reference' => $reference,
                'keep_tx' => $keep->id,
                'extra_tx' => $extras->pluck('id')->implode(','),
                'extra_amount' => number_format($extraAmount, 2, '.', ''),
                'balance' => $balance === null ? '—' : number_format($balance, 2, '.', ''),
                'result' => '',
            ];

            if ($wallet === null) {
                $row['result'] = 'skipped: wallet missing';
                $rows[] = $row;
                $skipped++;
                continue;
            }

            $newBalance = round($balance - $extraAmount, 2);
            $goesNegative = $newBalance < 0;

            if ($dryRun) {
                $row['result'] = $goesNegative
                    ? 'would reverse (balance '.$newBalance.')'
                    : 'would reverse';
                $rows[] = $row;
                $fixed++;
                $reversedAmount += $extraAmount;
                continue;
            }

            try {
                DB::transaction(function () use ($audit, $wallet, $extras, $extraAmount, $paymentId, $reference, $keep) {
                    $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                    $before = $locked->toArray();
                    foreach ($extras as $extra) {
                        // Soft-delete extras only — reducing balance once keeps the ledger in sync.
                        $extra->delete();
                    }

                    // Always deduct the duplicate amount, even if balance goes negative.
                    $locked->balance = round((float) $locked->balance - $extraAmount, 2);
                    $locked->save();

                    $audit->record(
                        'wallet.fix_duplicate_payment_credit',
                        sprintf(
                            'Removed %d duplicate credit(s) for payment #%d (%s); kept tx #%d',
                            $extras->count(),
                            $paymentId,
                            $reference !== '' ? $reference : 'no-ref',
                            $keep->id,
                        ),
                        'wallet',
                        'wallet',
                        $locked->id,
                        $before,
                        $locked->fresh(),
                    );
                });

                $row['result'] = $goesNegative
                    ? 'reversed (balance '.$newBalance.')'
                    : 'reversed';
                $rows[] = $row;
                $fixed++;
                $reversedAmount += $extraAmount;
            } catch (Throwable $e) {
                $row['result'] = 'failed: '.$e->getMessage();
                $rows[] = $row;
                $skipped++;
                $this->error(sprintf(
                    'Wallet #%d payment #%d failed: %s',
                    $walletId,
                    $paymentId,
                    $e->getMessage(),
                ));
            }
        }

        $this->table(
            ['Wallet', 'Payment', 'Reference', 'Keep TX', 'Extra TX', 'Amount', 'Balance', 'Result'],
            $rows,
        );

        $this->info(sprintf(
            '%s %d group(s), total %s. Skipped %d.',
            $dryRun ? 'Would reverse' : 'Reversed',
            $fixed,
            number_format($reversedAmount, 2),
            $skipped,
        ));

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
