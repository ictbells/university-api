<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Student;
use RuntimeException;

/** Semester fee must be settled before other student invoice payments for the current term.
 *  Enforcement applies only when a semester is marked current. With no live term, other
 *  payments proceed. Wallet funding (top-up) is never blocked.
 */
final class SemesterFeeAccess
{
    public const CATEGORY = 'semester_fee';

    public const BLOCKED_MESSAGE = 'Pay the semester fee for this term before other charges.';

    public const INSTALLMENT_BLOCKED_MESSAGE = 'Pay the semester fee for this term before creating a tuition installment.';

    public static function catalogIsCompulsory(): bool
    {
        $fee = FeeItem::query()
            ->where('category', self::CATEGORY)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        return $fee !== null && round((float) $fee->amount, 2) > 0;
    }

    public static function unpaidForTerm(Student $student, ?AcademicTerm $term = null): ?Invoice
    {
        $term ??= AcademicTerm::current();
        if (! $term) {
            return null;
        }

        $forTerm = Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', self::CATEGORY)
            ->where('academic_term_id', $term->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('id')
            ->first();
        if ($forTerm) {
            return $forTerm;
        }

        if (! self::catalogIsCompulsory()) {
            return null;
        }

        // Legacy rows without academic_term_id still block while the catalog fee is active.
        return Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', self::CATEGORY)
            ->whereNull('academic_term_id')
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('id')
            ->first();
    }

    public static function hasPaidForTerm(Student $student, ?AcademicTerm $term = null): bool
    {
        $term ??= AcademicTerm::current();
        if (! $term) {
            return false;
        }

        return Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', self::CATEGORY)
            ->where('academic_term_id', $term->id)
            ->where('status', 'paid')
            ->exists();
    }

    /**
     * True when the student must settle semester fee before other charges.
     * Only enforces when a current academic term is set and the catalog fee is
     * active — even if the invoice was never generated yet. With no live term,
     * other payments stay allowed until staff set a semester current.
     */
    public static function requiresSettlement(Student $student, ?AcademicTerm $term = null): bool
    {
        if (! self::catalogIsCompulsory()) {
            return false;
        }

        $term ??= AcademicTerm::current();
        if (! $term) {
            return false;
        }

        if (self::hasPaidForTerm($student, $term)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *   semester_fee_required: bool,
     *   semester_fee_invoice_id: int|null,
     *   semester_fee_balance: float,
     *   semester_fee_amount: float,
     *   semester_fee_term_id: int|null,
     *   semester_fee_error: string|null
     * }
     */
    public static function statusPayload(Student $student, ?AcademicTerm $term = null): array
    {
        $term ??= AcademicTerm::current();
        $unpaid = self::unpaidForTerm($student, $term);
        $catalogAmount = 0.0;
        if (self::catalogIsCompulsory()) {
            $catalogAmount = round((float) FeeItem::query()
                ->where('category', self::CATEGORY)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('amount'), 2);
        }

        $required = self::requiresSettlement($student, $term);
        $error = null;
        if ($required && ! $unpaid) {
            $error = 'Semester fee is required but no payable invoice is available. Refresh this page or contact the bursary.';
        }

        return [
            'semester_fee_required' => $required,
            'semester_fee_invoice_id' => $unpaid?->id,
            'semester_fee_balance' => $unpaid
                ? round((float) $unpaid->balance, 2)
                : $catalogAmount,
            'semester_fee_amount' => $catalogAmount,
            'semester_fee_term_id' => $term?->id,
            'semester_fee_error' => $error,
        ];
    }

    public static function assertCanPay(Student $student, Invoice $invoice): void
    {
        if ((string) $invoice->category === self::CATEGORY) {
            return;
        }

        if (! self::requiresSettlement($student)) {
            return;
        }

        throw new RuntimeException(self::BLOCKED_MESSAGE);
    }

    public static function assertCanCreateTuitionInstallment(Student $student): void
    {
        if (! self::requiresSettlement($student)) {
            return;
        }

        throw new RuntimeException(self::INSTALLMENT_BLOCKED_MESSAGE);
    }
}
