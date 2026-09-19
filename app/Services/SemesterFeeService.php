<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\SemesterFeeAccess;
use App\Support\Studentship;
use RuntimeException;

class SemesterFeeService
{
    public function __construct(
        private InvoiceService $invoices,
    ) {}

    public function resolveCatalogFee(): FeeItem
    {
        $fees = FeeItem::query()
            ->where('category', SemesterFeeAccess::CATEGORY)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($fees->isEmpty()) {
            throw new RuntimeException('No active semester fee item is configured. Create one in the fee catalog.');
        }
        if ($fees->count() > 1) {
            throw new RuntimeException('More than one active semester fee item exists. Keep only one active catalog item.');
        }

        $fee = $fees->first();
        if (round((float) $fee->amount, 2) <= 0) {
            throw new RuntimeException('Semester fee amount must be greater than zero. Update the fee catalog amount.');
        }

        return $fee;
    }

    public function resolveTerm(?int $academicTermId = null): AcademicTerm
    {
        if ($academicTermId) {
            return AcademicTerm::query()->with('session')->findOrFail($academicTermId);
        }

        $term = AcademicTerm::current();
        if (! $term) {
            throw new RuntimeException('No current academic term is set.');
        }

        return $term;
    }

    /**
     * Soft ensure for background callers — returns null when the student or catalog is not billable.
     */
    public function ensureForStudent(Student $student, ?AcademicTerm $term = null): ?Invoice
    {
        try {
            return $this->ensureForStudentOrFail($student, $term);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Create or repair the current-term semester fee invoice.
     * Skips cancelled rows and refreshes zero-amount unpaid invoices from the catalog.
     */
    public function ensureForStudentOrFail(Student $student, ?AcademicTerm $term = null): Invoice
    {
        $student->loadMissing('user');
        if (! $student->user) {
            throw new RuntimeException('Student login is missing; semester fee cannot be billed.');
        }
        if ($student->status !== Studentship::STATUS_ACTIVE || ! Studentship::isCurrent($student)) {
            throw new RuntimeException('Only active students receive the semester fee.');
        }

        $fee = $this->resolveCatalogFee();
        $term ??= $this->resolveTerm();
        $amount = round((float) $fee->amount, 2);

        $existing = Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', SemesterFeeAccess::CATEGORY)
            ->where('academic_term_id', $term->id)
            ->whereNotIn('status', ['cancelled', 'disabled'])
            ->orderBy('id')
            ->first();

        if ($existing) {
            if (in_array((string) $existing->status, ['unpaid', 'partial'], true)
                && (round((float) $existing->amount, 2) <= 0 || round((float) $existing->balance, 2) <= 0)
            ) {
                $existing->forceFill([
                    'amount' => $amount,
                    'full_amount' => $amount,
                    'balance' => $amount,
                    'status' => 'unpaid',
                    'wallet_allowed' => true,
                ])->save();
                $item = $existing->items()->orderBy('id')->first();
                if ($item) {
                    $item->forceFill([
                        'fee_item_id' => $fee->id,
                        'description' => $fee->name,
                        'amount' => $amount,
                    ])->save();
                } else {
                    $existing->items()->create([
                        'fee_item_id' => $fee->id,
                        'description' => $fee->name,
                        'amount' => $amount,
                    ]);
                }

                return $existing->fresh('items');
            }

            return $existing->loadMissing('items');
        }

        return $this->invoices->createForFee(
            $student->user,
            $fee,
            null,
            $student->id,
            null,
            null,
            $term->id,
        );
    }

    /**
     * @return array{created: int, skipped: int, failed: int, amount: float, academic_term_id: int, fee_item_id: int, failures: list<array{student_id: int, message: string}>}
     */
    public function generateForTerm(?int $academicTermId = null): array
    {
        $fee = $this->resolveCatalogFee();
        $term = $this->resolveTerm($academicTermId);
        $amount = round((float) $fee->amount, 2);

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];

        Student::query()
            ->with('user')
            ->where('status', Studentship::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(200, function ($students) use ($term, &$created, &$skipped, &$failed, &$failures) {
                foreach ($students as $student) {
                    if (! Studentship::isCurrent($student) || ! $student->user) {
                        $skipped++;

                        continue;
                    }

                    $hadOpenOrPaid = Invoice::query()
                        ->where('student_id', $student->id)
                        ->where('category', SemesterFeeAccess::CATEGORY)
                        ->where('academic_term_id', $term->id)
                        ->whereNotIn('status', ['cancelled', 'disabled'])
                        ->exists();
                    if ($hadOpenOrPaid) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $this->ensureForStudentOrFail($student, $term);
                        $created++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $failures[] = [
                            'student_id' => $student->id,
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'amount' => $amount,
            'academic_term_id' => $term->id,
            'fee_item_id' => $fee->id,
            'failures' => $failures,
        ];
    }
}
