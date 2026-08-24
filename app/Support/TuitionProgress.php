<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Student;

class TuitionProgress
{
    public static function percentPaid(Student $student): float
    {
        $invoices = $student->invoices()
            ->where('category', 'tuition')
            ->whereIn('status', ['paid', 'partial'])
            ->get();

        $best = 0.0;
        foreach ($invoices as $invoice) {
            $best = max($best, self::invoicePercent($invoice));
        }

        return round($best, 2);
    }

    public static function meetsMinimum(Student $student, float $minimum = 25): bool
    {
        return self::percentPaid($student) >= $minimum;
    }

    public static function invoicePercent(Invoice $invoice): float
    {
        if ($invoice->category !== 'tuition') {
            return 0;
        }

        if ($invoice->status === 'paid' && $invoice->installment_percent) {
            return (float) $invoice->installment_percent;
        }

        $full = (float) ($invoice->full_amount ?: $invoice->amount);
        if ($full <= 0) {
            return $invoice->status === 'paid' ? 100.0 : 0.0;
        }

        $paid = $full - (float) $invoice->balance;

        return round(max(0, min(100, ($paid / $full) * 100)), 2);
    }

    public static function tuitionConstraint(): \Closure
    {
        return function ($query) {
            $query->where('category', 'tuition')
                ->where(function ($inner) {
                    $inner->where(function ($paid) {
                        $paid->where('status', 'paid')
                            ->where(function ($percent) {
                                $percent->whereNull('installment_percent')
                                    ->orWhere('installment_percent', '>=', 25);
                            });
                    })->orWhere(function ($partial) {
                        $partial->whereIn('status', ['paid', 'partial'])
                            ->whereRaw('COALESCE(full_amount, amount) > 0')
                            ->whereRaw('((COALESCE(full_amount, amount) - balance) / COALESCE(full_amount, amount)) * 100 >= 25');
                    });
                });
        };
    }
}
