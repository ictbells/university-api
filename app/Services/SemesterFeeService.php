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
            ->chunkById(200, function ($students) use ($fee, $term, &$created, &$skipped, &$failed, &$failures) {
                foreach ($students as $student) {
                    if (! Studentship::isCurrent($student) || ! $student->user) {
                        $skipped++;

                        continue;
                    }

                    $exists = Invoice::query()
                        ->where('student_id', $student->id)
                        ->where('category', SemesterFeeAccess::CATEGORY)
                        ->where('academic_term_id', $term->id)
                        ->exists();
                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $this->invoices->createForFee(
                            $student->user,
                            $fee,
                            null,
                            $student->id,
                            null,
                            null,
                            $term->id,
                        );
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
