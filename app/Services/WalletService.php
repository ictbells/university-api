<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\Wallet;
use App\Support\FeeSchedule;
use Illuminate\Support\Str;
use RuntimeException;

class WalletService
{
    public function __construct(private InvoiceService $invoices, private AuditWriter $audit) {}

    public function credit(Wallet $wallet, float $amount, string $description, string $module, ?int $relatedId = null, ?string $reference = null): void
    {
        $before = $wallet->toArray();
        $wallet->balance = (float) $wallet->balance + $amount;
        $wallet->save();
        $wallet->transactions()->create([
            'type' => 'credit',
            'amount' => $amount,
            'reference' => $reference ?: 'WLT-'.Str::upper(Str::random(8)),
            'source_module' => $module,
            'related_id' => $relatedId,
            'description' => $description,
            'created_by' => auth()->id(),
        ]);
        $this->audit->record('wallet.credit', $description, 'wallet', 'wallet', $wallet->id, $before, $wallet->fresh());
    }

    public function debit(Wallet $wallet, float $amount, string $description, string $module, ?int $relatedId = null, ?string $reference = null): void
    {
        if ((float) $wallet->balance < $amount) {
            throw new RuntimeException('Insufficient wallet balance.');
        }
        $before = $wallet->toArray();
        $wallet->balance = (float) $wallet->balance - $amount;
        $wallet->save();
        $wallet->transactions()->create([
            'type' => 'debit',
            'amount' => $amount,
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
        $this->debit($student->wallet, (float) $invoice->balance, 'Payment for '.$invoice->number, 'finance', $invoice->id);
        $payAmount = (float) $invoice->balance;
        $paid = $this->invoices->applyPayment($invoice, $payAmount);
        $paid->payments()->create([
            'user_id' => $student->user_id,
            'method' => 'wallet',
            'amount' => $payAmount,
            'status' => 'successful',
            'reference' => 'WALLET-'.$invoice->number,
            'receipt_no' => 'RCP-'.Str::upper(Str::random(6)),
            'purpose' => $invoice->category,
        ]);

        return $paid;
    }
}
