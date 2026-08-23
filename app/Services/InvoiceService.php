<?php

namespace App\Services;

use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\User;

class InvoiceService
{
    public function createForFee(User $user, FeeItem $fee, ?int $applicationId = null, ?int $studentId = null): Invoice
    {
        $number = 'INV-'.now()->format('Ymd').'-'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $invoice = Invoice::query()->create([
            'number' => $number,
            'user_id' => $user->id,
            'student_id' => $studentId,
            'application_id' => $applicationId,
            'category' => $fee->category,
            'amount' => $fee->amount,
            'balance' => $fee->amount,
            'status' => 'unpaid',
            'wallet_allowed' => $fee->wallet_allowed,
        ]);
        $invoice->items()->create([
            'fee_item_id' => $fee->id,
            'description' => $fee->name,
            'amount' => $fee->amount,
        ]);

        return $invoice->fresh('items');
    }

    public function applyPayment(Invoice $invoice, float $amount): Invoice
    {
        $invoice->balance = max(0, (float) $invoice->balance - $amount);
        $invoice->status = $invoice->balance <= 0 ? 'paid' : 'partial';
        $invoice->save();

        return $invoice->fresh();
    }
}
