<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Invoice;
use App\Models\Student;
use RuntimeException;

/** Semester fee must be settled before other student invoice payments for the current term. */
final class SemesterFeeAccess
{
    public const CATEGORY = 'semester_fee';

    public const BLOCKED_MESSAGE = 'Pay the semester fee for this term before other charges.';

    public const INSTALLMENT_BLOCKED_MESSAGE = 'Pay the semester fee for this term before creating a tuition installment.';

    public static function unpaidForTerm(Student $student, ?AcademicTerm $term = null): ?Invoice
    {
        $term ??= AcademicTerm::current();
        if (! $term) {
            return null;
        }

        return Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', self::CATEGORY)
            ->where('academic_term_id', $term->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{
     *   semester_fee_required: bool,
     *   semester_fee_invoice_id: int|null,
     *   semester_fee_balance: float,
     *   semester_fee_term_id: int|null
     * }
     */
    public static function statusPayload(Student $student, ?AcademicTerm $term = null): array
    {
        $term ??= AcademicTerm::current();
        $unpaid = self::unpaidForTerm($student, $term);

        return [
            'semester_fee_required' => (bool) $unpaid,
            'semester_fee_invoice_id' => $unpaid?->id,
            'semester_fee_balance' => $unpaid ? round((float) $unpaid->balance, 2) : 0.0,
            'semester_fee_term_id' => $term?->id,
        ];
    }

    public static function assertCanPay(Student $student, Invoice $invoice): void
    {
        if ((string) $invoice->category === self::CATEGORY) {
            return;
        }

        $unpaid = self::unpaidForTerm($student);
        if (! $unpaid) {
            return;
        }

        throw new RuntimeException(self::BLOCKED_MESSAGE);
    }

    public static function assertCanCreateTuitionInstallment(Student $student): void
    {
        if (! self::unpaidForTerm($student)) {
            return;
        }

        throw new RuntimeException(self::INSTALLMENT_BLOCKED_MESSAGE);
    }
}
