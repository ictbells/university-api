<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Collection;

class InvoiceSettlement
{
    /**
     * Finance display: bill the full fee when set (e.g. tuition full_amount),
     * then paid/balance follow receipts against that figure.
     *
     * @param  Collection<int, Payment>|iterable<Payment>  $payments
     * @return array{billed: float, rebate: float, paid: float, balance: float, status: string, installment: float}
     */
    public static function for(Invoice $invoice, iterable $payments = []): array
    {
        $installment = round((float) $invoice->amount, 2);
        $billed = round((float) ($invoice->full_amount ?: $invoice->amount), 2);
        $rebate = round((float) ($invoice->rebate_total ?: 0), 2);

        if (in_array((string) $invoice->status, ['cancelled', 'disabled'], true)) {
            return [
                'billed' => $billed,
                'installment' => $installment,
                'rebate' => $rebate,
                'paid' => 0.0,
                'balance' => 0.0,
                'status' => (string) $invoice->status,
            ];
        }

        $paid = self::sumPayments($payments);
        $paid = round(min(max(0, $paid), max(0, $billed - $rebate)), 2);
        $balance = round(max(0, $billed - $rebate - $paid), 2);
        $status = $balance <= 0.009 ? 'paid' : ($paid > 0.009 ? 'partial' : 'unpaid');

        return [
            'billed' => $billed,
            'installment' => $installment,
            'rebate' => $rebate,
            'paid' => $paid,
            'balance' => $balance,
            'status' => $status,
        ];
    }

    /**
     * What is still collectable on this invoice document (installment / line charge).
     * Used to keep wallet/Paystack payable balance correct.
     *
     * @param  Collection<int, Payment>|iterable<Payment>  $payments
     * @return array{billed: float, rebate: float, paid: float, balance: float, status: string}
     */
    public static function payable(Invoice $invoice, iterable $payments = []): array
    {
        $billed = round((float) $invoice->amount, 2);
        $rebate = round((float) ($invoice->rebate_total ?: 0), 2);

        if (in_array((string) $invoice->status, ['cancelled', 'disabled'], true)) {
            return [
                'billed' => $billed,
                'rebate' => $rebate,
                'paid' => 0.0,
                'balance' => 0.0,
                'status' => (string) $invoice->status,
            ];
        }

        $paid = self::sumPayments($payments);
        $paid = round(min(max(0, $paid), max(0, $billed - $rebate)), 2);
        $balance = round(max(0, $billed - $rebate - $paid), 2);
        $status = $balance <= 0.009 ? 'paid' : ($paid > 0.009 ? 'partial' : 'unpaid');

        return [
            'billed' => $billed,
            'rebate' => $rebate,
            'paid' => $paid,
            'balance' => $balance,
            'status' => $status,
        ];
    }

    public static function countsTowardInvoice(Payment $payment): bool
    {
        if (in_array((string) $payment->purpose, ['wallet_topup', 'wallet_funding'], true)) {
            return false;
        }
        $status = $payment->status === 'paid' ? 'successful' : (string) $payment->status;

        return in_array($status, ['successful', 'paid'], true);
    }

    /**
     * Cash received against invoices. Wallet top-ups are not revenue.
     */
    public static function collectedRevenue(): float
    {
        $fromPayments = (float) Payment::query()
            ->whereNotNull('invoice_id')
            ->whereIn('status', ['successful', 'paid'])
            ->where(function ($query) {
                $query->whereNull('purpose')
                    ->orWhereNotIn('purpose', ['wallet_topup', 'wallet_funding']);
            })
            ->sum('amount');

        $fromPaidInvoicesWithoutReceipts = (float) Invoice::query()
            ->whereNotIn('status', ['cancelled', 'disabled', 'unpaid'])
            ->whereDoesntHave('payments', function ($query) {
                $query->whereIn('status', ['successful', 'paid'])
                    ->where(function ($inner) {
                        $inner->whereNull('purpose')
                            ->orWhereNotIn('purpose', ['wallet_topup', 'wallet_funding']);
                    });
            })
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > COALESCE(balance, 0) THEN amount - COALESCE(balance, 0) ELSE 0 END), 0) as total')
            ->value('total');

        return round($fromPayments + $fromPaidInvoicesWithoutReceipts, 2);
    }

    /**
     * Sync payable balance/status on the invoice; return finance display settlement.
     *
     * @param  Collection<int, Payment>|iterable<Payment>  $payments
     * @return array{billed: float, rebate: float, paid: float, balance: float, status: string, installment: float}
     */
    public static function sync(Invoice $invoice, iterable $payments = []): array
    {
        $payable = self::payable($invoice, $payments);
        if (! in_array($payable['status'], ['cancelled', 'disabled'], true)) {
            $balanceDrift = abs((float) $invoice->balance - $payable['balance']) > 0.009;
            $statusDrift = (string) $invoice->status !== $payable['status'];
            if ($balanceDrift || $statusDrift) {
                $invoice->forceFill([
                    'balance' => $payable['balance'],
                    'status' => $payable['status'],
                ])->save();
            }
        }

        return self::for($invoice, $payments);
    }

    /**
     * @param  Collection<int, Payment>|iterable<Payment>  $payments
     */
    private static function sumPayments(iterable $payments): float
    {
        $paid = 0.0;
        foreach ($payments as $payment) {
            if (! self::countsTowardInvoice($payment)) {
                continue;
            }
            $paid += (float) $payment->amount;
        }

        return $paid;
    }
}
