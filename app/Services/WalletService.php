<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\Wallet;
use App\Support\BillingCurrency;
use App\Support\FeeSchedule;
use Illuminate\Support\Str;
use RuntimeException;

class WalletService
{
    public function __construct(private InvoiceService $invoices, private AuditWriter $audit) {}

    public function credit(Wallet $wallet, float $amount, string $description, string $module, ?int $relatedId = null, ?string $reference = null, string $currency = BillingCurrency::NGN): void
    {
        $currency = BillingCurrency::normalize($currency);
        // Payment verify + webhook can race; never credit the same payment twice.
        if ($module === 'payments' && $relatedId) {
            $alreadyCredited = $wallet->transactions()
                ->where('type', 'credit')
                ->where('source_module', 'payments')
                ->where('related_id', $relatedId)
                ->exists();
            if ($alreadyCredited) {
                return;
            }
        }

        $before = $wallet->toArray();
        $field = BillingCurrency::isUsd($currency) ? 'balance_usd' : 'balance';
        $wallet->{$field} = (float) $wallet->{$field} + $amount;
        $wallet->save();
        $wallet->transactions()->create([
            'type' => 'credit',
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference ?: 'WLT-'.Str::upper(Str::random(8)),
            'source_module' => $module,
            'related_id' => $relatedId,
            'description' => $description,
            'created_by' => auth()->id(),
        ]);
        $this->audit->record('wallet.credit', $description, 'wallet', 'wallet', $wallet->id, $before, $wallet->fresh());
    }

    public function debit(Wallet $wallet, float $amount, string $description, string $module, ?int $relatedId = null, ?string $reference = null, string $currency = BillingCurrency::NGN): void
    {
        $currency = BillingCurrency::normalize($currency);
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Wallet debit amount must be greater than zero.');
        }

        // Negative or short balances cannot spend — including after duplicate-credit repairs.
        $field = BillingCurrency::isUsd($currency) ? 'balance_usd' : 'balance';
        $balance = round((float) $wallet->{$field}, 2);
        if ($balance <= 0 || $balance < $amount) {
            throw new RuntimeException(BillingCurrency::isUsd($currency)
                ? 'Insufficient USD wallet balance.'
                : 'Insufficient wallet balance.');
        }

        $before = $wallet->toArray();
        $wallet->{$field} = round($balance - $amount, 2);
        $wallet->save();
        $wallet->transactions()->create([
            'type' => 'debit',
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference ?: 'WLT-'.Str::upper(Str::random(8)),
            'source_module' => $module,
            'related_id' => $relatedId,
            'description' => $description,
            'created_by' => auth()->id(),
        ]);
        $this->audit->record('wallet.debit', $description, 'wallet', 'wallet', $wallet->id, $before, $wallet->fresh());
    }

    public function payInvoice(Student $student, Invoice $invoice): Invoice
    {
        if (! $invoice->isPayable()) {
            throw new RuntimeException('This invoice cannot be paid.');
        }
        if (FeeSchedule::walletBlocked($invoice->category)) {
            throw new RuntimeException('This invoice cannot be paid from the wallet.');
        }
        if ($invoice->user_id !== $student->user_id) {
            throw new RuntimeException('Invoice does not belong to this student.');
        }
        $currency = BillingCurrency::normalize($invoice->currency);
        if ($currency !== BillingCurrency::forStudent($student)) {
            throw new RuntimeException('This invoice currency does not match the student billing currency.');
        }
        $this->debit($student->wallet, (float) $invoice->balance, 'Payment for '.$invoice->number, 'finance', $invoice->id, null, $currency);
        $payAmount = (float) $invoice->balance;
        $paid = $this->invoices->applyPayment($invoice, $payAmount);
        $paid->payments()->create([
            'user_id' => $student->user_id,
            'method' => 'wallet',
            'amount' => $payAmount,
            'currency' => $currency,
            'status' => 'successful',
            'reference' => 'WALLET-'.$invoice->number,
            'receipt_no' => $invoice->number,
            'purpose' => $invoice->category,
        ]);

        return $paid;
    }
}
