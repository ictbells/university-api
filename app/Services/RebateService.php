<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceRebate;
use App\Models\MedicalBill;
use App\Models\Payment;
use App\Models\RebateType;
use App\Models\User;
use App\Support\FeeSchedule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RebateService
{
    public function computeAmount(Invoice $invoice, string $kind, float $value): float
    {
        $balance = round((float) $invoice->balance, 2);
        if ($balance <= 0) {
            return 0;
        }

        $billed = round((float) $invoice->amount, 2);
        $computed = $kind === 'percent'
            ? round($billed * ($value / 100), 2)
            : round($value, 2);

        return round(min(max($computed, 0), $balance), 2);
    }

    public function apply(Invoice $invoice, RebateType $type, string $kind, float $value, string $reason, User $actor): InvoiceRebate
    {
        $this->assertCanReceiveRebate($invoice);

        if (! $type->is_active) {
            throw new RuntimeException('This rebate type is inactive.');
        }

        $amount = $this->computeAmount($invoice, $kind, $value);
        if ($amount <= 0) {
            throw new RuntimeException('Rebate amount must be greater than zero and cannot exceed the remaining balance.');
        }

        return DB::transaction(function () use ($invoice, $type, $kind, $value, $amount, $reason, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertCanReceiveRebate($invoice);

            $amount = $this->computeAmount($invoice, $kind, $value);
            if ($amount <= 0) {
                throw new RuntimeException('Rebate amount must be greater than zero and cannot exceed the remaining balance.');
            }

            $rebate = InvoiceRebate::query()->create([
                'invoice_id' => $invoice->id,
                'rebate_type_id' => $type->id,
                'kind' => $kind,
                'value' => $value,
                'amount' => $amount,
                'reason' => $reason,
                'applied_by' => $actor->id,
            ]);

            $item = $invoice->items()->create([
                'fee_item_id' => null,
                'description' => 'Rebate: '.$type->name,
                'amount' => -1 * $amount,
            ]);
            $rebate->invoice_item_id = $item->id;
            $rebate->save();

            $invoice->rebate_total = round((float) $invoice->rebate_total + $amount, 2);
            $invoice->balance = round(max(0, (float) $invoice->balance - $amount), 2);
            $this->syncStatus($invoice);
            $invoice->save();

            return $rebate->fresh(['rebateType', 'appliedBy', 'invoiceItem']);
        });
    }

    public function reverse(Invoice $invoice, InvoiceRebate $rebate, string $reason, User $actor): InvoiceRebate
    {
        if ((int) $rebate->invoice_id !== (int) $invoice->id) {
            throw new RuntimeException('This rebate does not belong to the invoice.');
        }
        if ($rebate->reversed_at) {
            throw new RuntimeException('This rebate has already been reversed.');
        }
        if (in_array($invoice->status, ['cancelled'], true)) {
            throw new RuntimeException('Disabled invoices cannot have rebates reversed.');
        }

        $laterRebate = InvoiceRebate::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('reversed_at')
            ->where('id', '>', $rebate->id)
            ->exists();
        if ($laterRebate) {
            throw new RuntimeException('Reverse the most recent rebate first.');
        }

        $laterPayment = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'successful')
            ->where('created_at', '>', $rebate->created_at)
            ->exists();
        if ($laterPayment) {
            throw new RuntimeException('This rebate cannot be reversed because a payment was made afterwards.');
        }

        return DB::transaction(function () use ($invoice, $rebate, $reason, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $rebate = InvoiceRebate::query()->lockForUpdate()->findOrFail($rebate->id);

            if ($rebate->reversed_at) {
                throw new RuntimeException('This rebate has already been reversed.');
            }

            $amount = round((float) $rebate->amount, 2);
            $rebate->reversed_at = now();
            $rebate->reversed_by = $actor->id;
            $rebate->reverse_reason = $reason;
            $rebate->save();

            if ($rebate->invoiceItem) {
                $rebate->invoiceItem->delete();
            }

            $invoice->rebate_total = round(max(0, (float) $invoice->rebate_total - $amount), 2);
            $invoice->balance = round((float) $invoice->balance + $amount, 2);
            $this->syncStatus($invoice);
            $invoice->save();

            return $rebate->fresh(['rebateType', 'appliedBy', 'reversedBy']);
        });
    }

    private function assertCanReceiveRebate(Invoice $invoice): void
    {
        if (! $invoice->isPayable()) {
            throw new RuntimeException('Rebates can only be applied to unpaid or partially paid invoices.');
        }
        if (FeeSchedule::walletBlocked((string) $invoice->category) || ! $invoice->wallet_allowed) {
            throw new RuntimeException('Application and acceptance fees cannot be rebated.');
        }
    }

    private function syncStatus(Invoice $invoice): void
    {
        $balance = round((float) $invoice->balance, 2);
        if ($balance <= 0) {
            $invoice->balance = 0;
            $invoice->status = 'paid';
        } else {
            $hasPayment = Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'successful')
                ->exists();
            $invoice->status = ($hasPayment || round((float) $invoice->rebate_total, 2) > 0)
                ? 'partial'
                : 'unpaid';
        }

        if ($invoice->category === 'medical') {
            MedicalBill::query()
                ->where('invoice_id', $invoice->id)
                ->update(['status' => $invoice->status]);
        }
    }
}
