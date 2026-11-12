<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

class TuitionProgress
{
    private static bool $currentSessionResolved = false;

    private static ?int $currentSessionId = null;

    public static function flushCache(): void
    {
        self::$currentSessionResolved = false;
        self::$currentSessionId = null;
    }

    public static function currentSessionId(): ?int
    {
        if (self::$currentSessionResolved) {
            return self::$currentSessionId;
        }
        self::$currentSessionResolved = true;
        $raw = AcademicTerm::query()->where('is_current', true)->value('academic_session_id');
        self::$currentSessionId = $raw ? (int) $raw : null;

        return self::$currentSessionId;
    }

    /**
     * Tuition paid toward the live academic session and the student's current level.
     * Previous-level receipts do not count. When the current term session has no matching
     * paid tuition but the student has paid on another stamped session for this level
     * (common when invoices were raised under 2026/2027 while is_current still pointed
     * elsewhere), that paid session is used so hostel/registration stay unlocked.
     */
    public static function currentSessionPercent(Student $student): float
    {
        return self::currentSessionPercentsFor([$student])[(int) $student->id] ?? 0.0;
    }

    /**
     * @param  iterable<int, Student>  $students
     * @return array<int, float>
     */
    public static function currentSessionPercentsFor(iterable $students): array
    {
        $students = collect($students)->values();
        if ($students->isEmpty()) {
            return [];
        }

        $currentId = self::currentSessionId();
        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();
        $userIds = $students->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $invoices = Invoice::query()
            ->where('category', 'tuition')
            ->whereIn('status', ['paid', 'partial'])
            ->where(function ($builder) use ($studentIds, $userIds) {
                $builder->whereIn('student_id', $studentIds);
                if ($userIds !== []) {
                    $builder->orWhere(function ($byUser) use ($userIds) {
                        $byUser->whereIn('user_id', $userIds)
                            ->where(function ($sid) {
                                $sid->whereNull('student_id')->orWhere('student_id', 0);
                            });
                    });
                }
            })
            ->with('items')
            ->orderByDesc('id')
            ->get();

        $byStudent = [];
        foreach ($studentIds as $studentId) {
            $byStudent[$studentId] = collect();
        }
        $studentsByUser = $students->keyBy(fn (Student $student) => (int) ($student->user_id ?: 0));

        foreach ($invoices as $invoice) {
            $studentId = (int) ($invoice->student_id ?: 0);
            if ($studentId > 0 && isset($byStudent[$studentId])) {
                $byStudent[$studentId]->push($invoice);

                continue;
            }
            $userId = (int) ($invoice->user_id ?: 0);
            if ($userId > 0 && ($student = $studentsByUser->get($userId))) {
                $byStudent[(int) $student->id]->push($invoice);
            }
        }

        $result = [];
        foreach ($students as $student) {
            $result[(int) $student->id] = self::percentFromInvoiceCollection(
                $student,
                $byStudent[(int) $student->id] ?? collect(),
                $currentId,
            );
        }

        return $result;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Invoice>  $invoices
     */
    private static function percentFromInvoiceCollection(Student $student, $invoices, ?int $currentId): float
    {
        $level = StudentAcademicLevel::primaryFeeLevelCode($student);

        if ($currentId) {
            $paid = self::bestPercentInCollection($student, $invoices, $currentId, true, $level);
            if ($paid > 0) {
                return $paid;
            }
        }

        $latestPaidSessionId = $invoices
            ->first(fn (Invoice $invoice) => (int) ($invoice->academic_session_id ?: 0) > 0)
            ?->academic_session_id;
        $latestPaidSessionId = $latestPaidSessionId ? (int) $latestPaidSessionId : null;

        if ($latestPaidSessionId && $latestPaidSessionId !== $currentId) {
            $paid = self::bestPercentInCollection($student, $invoices, $latestPaidSessionId, true, $level);
            if ($paid > 0) {
                return $paid;
            }
        }

        if (! $currentId) {
            return self::bestPercentInCollection($student, $invoices, null, true, $level);
        }

        return 0.0;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Invoice>  $invoices
     */
    private static function bestPercentInCollection(
        Student $student,
        $invoices,
        ?int $sessionId,
        bool $includeLegacy,
        ?string $levelCode,
    ): float {
        $codes = [];
        if ($levelCode !== null && $levelCode !== '') {
            $codes = StudentAcademicLevel::feeLevelCodes($student);
            $codes[] = $levelCode;
            $unique = [];
            foreach ($codes as $code) {
                $code = trim((string) $code);
                if ($code === '') {
                    continue;
                }
                $unique[strtolower($code)] = $code;
            }
            $codes = array_values($unique);
        }
        $codeLookup = array_change_key_case(array_flip($codes), CASE_LOWER);

        $best = 0.0;
        foreach ($invoices as $invoice) {
            if ($sessionId) {
                $invoiceSession = $invoice->academic_session_id ? (int) $invoice->academic_session_id : null;
                if ($invoiceSession !== $sessionId && ! ($includeLegacy && $invoiceSession === null)) {
                    continue;
                }
            }

            if ($codes !== []) {
                $invoiceLevel = trim((string) ($invoice->level_code ?? ''));
                if ($invoiceLevel !== '' && strtolower($invoiceLevel) !== 'all' && ! isset($codeLookup[strtolower($invoiceLevel)])) {
                    continue;
                }
            }

            $best = max($best, self::invoicePercent($invoice));
        }

        return round($best, 2);
    }

    public static function percentPaid(Student $student, ?int $sessionId = null, bool $includeLegacy = false, ?string $levelCode = null): float
    {
        $sessionId ??= $includeLegacy ? null : self::currentSessionId();
        if (! $sessionId && ! $includeLegacy) {
            return 0.0;
        }
        if ($levelCode === null || $levelCode === '') {
            if (! $includeLegacy && $student->current_level !== null && $student->current_level !== '') {
                $levelCode = (string) $student->current_level;
            } elseif ($includeLegacy) {
                $levelCode = StudentAcademicLevel::primaryFeeLevelCode($student);
            }
        }

        $query = self::tuitionInvoiceQuery($student)
            ->whereIn('status', ['paid', 'partial']);

        if ($sessionId) {
            $query->where(function ($builder) use ($sessionId, $includeLegacy) {
                $builder->where('academic_session_id', $sessionId);
                if ($includeLegacy) {
                    $builder->orWhereNull('academic_session_id');
                }
            });
        }

        if ($levelCode) {
            self::constrainLevel($query, $student, $levelCode);
        }

        $invoices = $query->with('items')->get();

        $best = 0.0;
        foreach ($invoices as $invoice) {
            $best = max($best, self::invoicePercent($invoice));
        }

        return round($best, 2);
    }

    /**
     * Installment options still available after already-paid tuition (e.g. hide 25% once 1st is paid).
     * Pay-at-once schedules (Full 100% only) expose 100% alone.
     *
     * @return list<int>
     */
    public static function availableInstallmentPercents(Student $student, ?int $sessionId = null): array
    {
        $paid = $sessionId
            ? self::percentPaid(
                $student,
                $sessionId,
                includeLegacy: true,
                levelCode: StudentAcademicLevel::primaryFeeLevelCode($student),
            )
            : self::currentSessionPercent($student);

        $bands = FeeSchedule::INSTALLMENT_PERCENTS;
        if ($student->program_id) {
            $lines = ProgrammeFeeResolver::forStudent($student);
            $bands = FeeSchedule::installmentPercentsForSchedule($lines);
        }

        return array_values(array_filter(
            $bands,
            static fn (int $percent) => $percent > $paid
        ));
    }

    public static function meetsMinimum(Student $student, float $minimum = 25, ?int $sessionId = null): bool
    {
        if ($sessionId === null) {
            return self::currentSessionPercent($student) >= $minimum;
        }

        return self::percentPaid(
            $student,
            $sessionId,
            includeLegacy: true,
            levelCode: StudentAcademicLevel::primaryFeeLevelCode($student),
        ) >= $minimum;
    }

    public static function invoicePercent(Invoice $invoice): float
    {
        if ($invoice->category !== 'tuition') {
            return 0;
        }

        $full = (float) ($invoice->full_amount ?: $invoice->amount);
        $billed = (float) $invoice->amount;
        $paidOnInvoice = max(0, $billed - (float) $invoice->balance);
        $actual = $full > 0
            ? round(max(0, min(100, ($paidOnInvoice / $full) * 100)), 2)
            : ($invoice->status === 'paid' ? 100.0 : 0.0);

        if ($invoice->status === 'paid' && $invoice->installment_percent) {
            $claimed = (float) $invoice->installment_percent;

            // Cap only grossly underpaid "pay in full" claims (e.g. ₦10k of ₦742k marked 100%).
            // Tranche installments often sum to less than percent×full_amount; trust the claimed band.
            if (self::shouldCapClaimedPercent($claimed, $actual)) {
                return round(min($claimed, $actual), 2);
            }

            return $claimed;
        }

        // Tranche invoices may omit installment_percent but still label "1st 25%" in line items.
        if ($invoice->status === 'paid') {
            $fromLabel = self::percentFromShareLabel($invoice);
            if ($fromLabel !== null) {
                if (self::shouldCapClaimedPercent($fromLabel, $actual)) {
                    return round(min($fromLabel, $actual), 2);
                }

                return $fromLabel;
            }
        }

        if ($full <= 0) {
            return $invoice->status === 'paid' ? 100.0 : 0.0;
        }

        // Progress toward the full-year fee: only count what was paid on this invoice.
        return $actual;
    }

    /**
     * True when a claimed installment band (especially Full 100%) far exceeds what was paid
     * against full_amount — the wrong-fee Full 100% case.
     */
    private static function shouldCapClaimedPercent(float $claimed, float $actual): bool
    {
        if ($claimed < 99.5) {
            return false;
        }

        // Paid less than half of a claimed full settlement.
        return $actual < ($claimed * 0.5);
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

    /**
     * @return Builder<Invoice>
     */
    private static function tuitionInvoiceQuery(Student $student): Builder
    {
        return Invoice::query()
            ->where('category', 'tuition')
            ->where(function ($builder) use ($student) {
                $builder->where('student_id', $student->id);
                if ($student->user_id) {
                    // History lists by user_id; some legacy tuition rows omit student_id.
                    $builder->orWhere(function ($byUser) use ($student) {
                        $byUser->where('user_id', $student->user_id)
                            ->where(function ($sid) {
                                $sid->whereNull('student_id')
                                    ->orWhere('student_id', 0);
                            });
                    });
                }
            });
    }

    private static function constrainLevel(Builder $query, Student $student, string $levelCode): void
    {
        $codes = StudentAcademicLevel::feeLevelCodes($student);
        $codes[] = $levelCode;
        $unique = [];
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $unique[strtolower($code)] = $code;
        }
        $codes = array_values($unique);

        $query->where(function ($builder) use ($codes) {
            $builder->whereNull('level_code')
                ->orWhere('level_code', 'all');
            if ($codes !== []) {
                $builder->orWhereIn('level_code', $codes);
            }
        });
    }

    private static function latestPaidTuitionSessionId(Student $student): ?int
    {
        $id = self::tuitionInvoiceQuery($student)
            ->whereIn('status', ['paid', 'partial'])
            ->whereNotNull('academic_session_id')
            ->latest('id')
            ->value('academic_session_id');

        return $id ? (int) $id : null;
    }

    private static function percentFromShareLabel(Invoice $invoice): ?float
    {
        $label = strtolower(trim((string) ($invoice->shareLabel() ?: '')));
        if ($label === '') {
            return null;
        }
        if (str_contains($label, '1st 25') || $label === '25% installment') {
            return 25.0;
        }
        if (str_contains($label, '2nd 25') || $label === '50% installment') {
            return 50.0;
        }
        if (str_contains($label, '3rd 25') || $label === '75% installment') {
            return 75.0;
        }
        if (str_contains($label, '4th 25') || str_contains($label, 'full 100') || $label === '100% installment') {
            return 100.0;
        }

        return null;
    }
}
