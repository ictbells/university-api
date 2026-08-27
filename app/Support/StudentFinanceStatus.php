<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Support\Collection;

class StudentFinanceStatus
{
    /**
     * Bursary position: bill 100% school fees plus other invoices.
     * Cleared only when 100% of school fees have been paid.
     *
     * @param  Collection<int, Invoice>|null  $invoices
     * @return array{
     *   billed: float,
     *   rebate_total: float,
     *   paid: float,
     *   outstanding: float,
     *   school_fees: float,
     *   tuition_paid: float,
     *   tuition_outstanding: float,
     *   clearance: string,
     *   invoice_count: int,
     *   open_count: int
     * }
     */
    public static function summarize(Student $student, ?Collection $invoices = null): array
    {
        $invoices ??= $student->invoices()->with(['payments'])->get();
        $active = $invoices->filter(
            fn (Invoice $invoice) => ! in_array((string) $invoice->status, ['cancelled', 'disabled'], true)
        );

        $settlements = [];
        foreach ($invoices as $invoice) {
            $settlements[$invoice->id] = InvoiceSettlement::for($invoice, $invoice->payments ?? collect());
        }

        $tuition = $active->filter(fn (Invoice $invoice) => $invoice->category === 'tuition');
        $other = $active->filter(fn (Invoice $invoice) => $invoice->category !== 'tuition');

        $schedule = ProgrammeFeeResolver::totalForStudent($student);
        $invoiceFull = (float) $tuition->max(
            fn (Invoice $invoice) => (float) ($invoice->full_amount ?: $invoice->amount)
        );
        $schoolFees = round(max($schedule, $invoiceFull), 2);

        $tuitionPaid = 0.0;
        $tuitionRebate = 0.0;
        foreach ($tuition as $invoice) {
            $row = $settlements[$invoice->id];
            $tuitionPaid += $row['paid'];
            $tuitionRebate += $row['rebate'];
        }
        $tuitionPaid = round(min($tuitionPaid, max(0, $schoolFees - $tuitionRebate)), 2);
        $tuitionOutstanding = round(max(0, $schoolFees - $tuitionRebate - $tuitionPaid), 2);

        $otherBilled = 0.0;
        $otherPaid = 0.0;
        $otherRebate = 0.0;
        $otherOutstanding = 0.0;
        foreach ($other as $invoice) {
            $row = $settlements[$invoice->id];
            $otherBilled += $row['billed'];
            $otherPaid += $row['paid'];
            $otherRebate += $row['rebate'];
            $otherOutstanding += $row['balance'];
        }

        $billed = round($schoolFees + $otherBilled, 2);
        $rebateTotal = round($tuitionRebate + $otherRebate, 2);
        $paid = round($tuitionPaid + $otherPaid, 2);
        $outstanding = round($tuitionOutstanding + $otherOutstanding, 2);
        $cleared = $schoolFees > 0.009 && $tuitionOutstanding <= 0.009;

        $openCount = $active->filter(function (Invoice $invoice) use ($settlements) {
            return in_array($settlements[$invoice->id]['status'], ['unpaid', 'partial'], true);
        })->count();

        return [
            'billed' => $billed,
            'rebate_total' => $rebateTotal,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'school_fees' => $schoolFees,
            'tuition_paid' => $tuitionPaid,
            'tuition_outstanding' => $tuitionOutstanding,
            'clearance' => $cleared ? 'cleared' : 'outstanding',
            'invoice_count' => $invoices->count(),
            'open_count' => $openCount,
        ];
    }
}
