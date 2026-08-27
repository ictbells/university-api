<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Invoice;
use App\Models\Student;

class TuitionProgress
{
    public static function currentSessionId(): ?int
    {
        $id = AcademicTerm::query()->where('is_current', true)->value('academic_session_id');

        return $id ? (int) $id : null;
    }

    public static function percentPaid(Student $student, ?int $sessionId = null, bool $includeLegacy = false, ?string $levelCode = null): float
    {
        $sessionId ??= $includeLegacy ? null : self::currentSessionId();
        $query = $student->invoices()
            ->where('category', 'tuition')
            ->whereIn('status', ['paid', 'partial']);
        if ($sessionId) {
            $query->where(function ($builder) use ($sessionId, $includeLegacy) {
                $builder->where('academic_session_id', $sessionId);
                if ($includeLegacy) {
                    $builder->orWhereNull('academic_session_id');
                }
            });
        }
        if ($includeLegacy && $levelCode) {
            $query->where(function ($builder) use ($levelCode) {
                $builder->whereNull('level_code')
                    ->orWhere('level_code', 'all')
                    ->orWhere('level_code', $levelCode);
            });
        }

        $invoices = $query->get();

        $best = 0.0;
        foreach ($invoices as $invoice) {
            $best = max($best, self::invoicePercent($invoice));
        }

        return round($best, 2);
    }

    /**
     * Installment options still available after already-paid tuition (e.g. hide 25% once 1st is paid).
     *
     * @return list<int>
     */
    public static function availableInstallmentPercents(Student $student, ?int $sessionId = null): array
    {
        $paid = self::percentPaid($student, $sessionId);

        return array_values(array_filter(
            FeeSchedule::INSTALLMENT_PERCENTS,
            static fn (int $percent) => $percent > $paid
        ));
    }

    public static function meetsMinimum(Student $student, float $minimum = 25, ?int $sessionId = null): bool
    {
        return self::percentPaid($student, $sessionId) >= $minimum;
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
        $billed = (float) $invoice->amount;
        if ($full <= 0) {
            return $invoice->status === 'paid' ? 100.0 : 0.0;
        }

        // Progress toward the full-year fee: only count what was paid on this invoice.
        $paidOnInvoice = max(0, $billed - (float) $invoice->balance);

        return round(max(0, min(100, ($paidOnInvoice / $full) * 100)), 2);
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
                            ->whereRaw('((amount - balance) / COALESCE(full_amount, amount)) * 100 >= 25');
                    });
                });
        };
    }
}
