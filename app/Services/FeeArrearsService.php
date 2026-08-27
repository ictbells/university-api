<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentLevelProgression;
use App\Support\TuitionProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FeeArrearsService
{
    public function __construct(private InvoiceService $invoices) {}

    public function ensureForStudent(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $locked = Student::query()->whereKey($student->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }
            $locked->loadMissing(['program', 'application']);
            $this->backfillInvoiceSessions($locked);

            $progressions = StudentLevelProgression::query()
                ->with('session')
                ->where('student_id', $locked->id)
                ->orderBy('id')
                ->get();

            foreach ($progressions as $row) {
                if (! $row->session) {
                    continue;
                }
                $this->invoiceRemaining($locked, $row->session, (string) $row->from_level);
            }
        });
    }

    public function invoiceRemaining(Student $student, AcademicSession $session, string $levelCode): ?Invoice
    {
        if ($levelCode === '' || ! $student->program_id) {
            return null;
        }

        $expected = $this->invoices->remainingTuitionAmount($student, $session, $levelCode);
        $open = Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', 'tuition')
            ->where('academic_session_id', $session->id)
            ->where('level_code', $levelCode)
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('id')
            ->get();

        $kept = null;
        foreach ($open as $invoice) {
            $hasPayment = $invoice->payments()->where('status', 'successful')->exists();
            $matches = $expected > 0.009 && abs((float) $invoice->amount - $expected) <= 0.009;
            if ($hasPayment) {
                $kept ??= $invoice;
                continue;
            }
            if ($matches && $kept === null) {
                $kept = $invoice;
                continue;
            }
            if ($invoice->status === 'unpaid') {
                $this->invoices->disable($invoice, 'Replaced with the remaining 3rd and 4th 25% for this level.');
            }
        }
        if ($kept) {
            return $kept;
        }

        if ($expected <= 0.009) {
            return null;
        }

        try {
            return $this->invoices->createTuitionInvoice($student, 100, null, $session, $levelCode);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function priorUnpaid(Student $student): Collection
    {
        $currentSessionId = TuitionProgress::currentSessionId();
        $currentLevel = $student->current_level !== null ? (string) $student->current_level : null;

        return Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->where(function ($query) use ($currentSessionId, $currentLevel) {
                if ($currentSessionId) {
                    $query->where(function ($session) use ($currentSessionId) {
                        $session->whereNotNull('academic_session_id')
                            ->where('academic_session_id', '!=', $currentSessionId);
                    });
                }
                if ($currentLevel) {
                    $query->orWhere(function ($level) use ($currentLevel) {
                        $level->whereNotNull('level_code')
                            ->where('level_code', '!=', 'all')
                            ->where('level_code', '!=', $currentLevel);
                    });
                }
            })
            ->orderBy('id')
            ->get();
    }

    public function assertCanPay(Student $student, Invoice $invoice): void
    {
        $prior = $this->priorUnpaid($student);
        if ($prior->isEmpty()) {
            return;
        }
        if ($prior->contains(fn (Invoice $row) => (int) $row->id === (int) $invoice->id)) {
            return;
        }

        throw new RuntimeException(
            'Pay unpaid invoices from previous sessions and levels before paying current-session charges.'
        );
    }

    public function assertPriorSettled(Student $student): void
    {
        $prior = $this->priorUnpaid($student);
        if ($prior->isNotEmpty()) {
            throw new RuntimeException(
                'Pay unpaid invoices from previous sessions and levels before current-session fees or course registration.'
            );
        }
    }

    public function outstandingAmount(Student $student): float
    {
        return round((float) Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->sum('balance'), 2);
    }

    public function openCount(Student $student): int
    {
        return Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->count();
    }

    private function backfillInvoiceSessions(Student $student): void
    {
        $first = StudentLevelProgression::query()
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->first();

        if ($first) {
            Invoice::query()
                ->where('student_id', $student->id)
                ->whereNull('academic_session_id')
                ->where('created_at', '<=', $first->created_at)
                ->update(['academic_session_id' => $first->academic_session_id]);
            Invoice::query()
                ->where('student_id', $student->id)
                ->whereNull('level_code')
                ->where('created_at', '<=', $first->created_at)
                ->update(['level_code' => (string) $first->from_level]);

            return;
        }

        $sessionId = $student->application?->academic_session_id ?: TuitionProgress::currentSessionId();
        if (! $sessionId) {
            return;
        }
        $level = $student->current_level !== null ? (string) $student->current_level : null;
        Invoice::query()
            ->where('student_id', $student->id)
            ->whereNull('academic_session_id')
            ->update(['academic_session_id' => $sessionId]);
        if ($level) {
            Invoice::query()
                ->where('student_id', $student->id)
                ->whereNull('level_code')
                ->update(['level_code' => $level]);
        }
    }
}
