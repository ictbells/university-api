<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentLevelProgression;
use App\Support\TuitionProgress;
use Illuminate\Support\Collection;
use RuntimeException;

class FeeArrearsService
{
    public function __construct(private InvoiceService $invoices) {}

    public function ensureForStudent(Student $student): void
    {
        $student->loadMissing(['program', 'application']);
        $this->backfillInvoiceSessions($student);

        $progressions = StudentLevelProgression::query()
            ->with('session')
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get();

        foreach ($progressions as $row) {
            if (! $row->session) {
                continue;
            }
            $this->invoiceRemaining($student, $row->session, (string) $row->from_level);
        }
    }

    public function invoiceRemaining(Student $student, AcademicSession $session, string $levelCode): ?Invoice
    {
        if ($levelCode === '' || ! $student->program_id) {
            return null;
        }

        $paid = TuitionProgress::percentPaid($student, (int) $session->id);
        if ($paid >= 100) {
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
