<?php

namespace App\Support;

use App\Models\ProgrammeFee;
use App\Models\Student;
use Illuminate\Support\Collection;

class ProgrammeFeeResolver
{
    /**
     * @return Collection<int, ProgrammeFee>
     */
    public static function forProgram(
        int $programId,
        ?string $levelCode = null,
        ?string $semester = null,
    ): Collection {
        $query = ProgrammeFee::query()
            ->with('feeItem')
            ->where('program_id', $programId)
            ->where('is_active', true)
            ->whereHas('feeItem', fn ($fee) => $fee->where('is_active', true));

        if ($levelCode !== null && $levelCode !== '') {
            $query->where(function ($builder) use ($levelCode) {
                $builder->where('level_code', 'all')
                    ->orWhere('level_code', $levelCode);
            });
        }

        if ($semester !== null && $semester !== '' && $semester !== 'both') {
            $query->where(function ($builder) use ($semester) {
                $builder->where('semester', $semester)
                    ->orWhere('semester', 'both');
            });
        }

        return $query->orderBy('display_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, ProgrammeFee>
     */
    public static function forStudent(Student $student, ?string $semester = null): Collection
    {
        $student->loadMissing('program');
        if (! $student->program_id) {
            return collect();
        }

        $levelCode = $student->current_level !== null
            ? (string) $student->current_level
            : null;

        return self::forProgram((int) $student->program_id, $levelCode, $semester);
    }

    public static function totalForProgram(
        int $programId,
        ?string $levelCode = null,
        ?string $semester = null,
    ): float {
        return self::scheduleFullAmount(self::forProgram($programId, $levelCode, $semester));
    }

    public static function totalForStudent(Student $student, ?string $semester = null): float
    {
        return self::scheduleFullAmount(self::forStudent($student, $semester));
    }

    /**
     * Full-session schedule total. When finance tags installment tranches, sum the
     * 1st–4th 25% slices (not the optional 100% pay-at-once package) so totals are not doubled.
     *
     * @param  Collection<int, ProgrammeFee>  $lines
     */
    public static function scheduleFullAmount(Collection $lines): float
    {
        $tagged = $lines->filter(fn (ProgrammeFee $fee) => self::isTaggedSlice($fee));
        if ($tagged->isEmpty()) {
            return round((float) $lines->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);
        }

        $slices = $tagged->filter(
            fn (ProgrammeFee $fee) => in_array((int) $fee->feeItem->installment_tranche, [1, 2, 3, 4], true)
        );
        if ($slices->isNotEmpty()) {
            $untagged = $lines->reject(fn (ProgrammeFee $fee) => self::isTaggedSlice($fee));

            return round((float) $slices->sum(fn (ProgrammeFee $fee) => $fee->effective_amount)
                + $untagged->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);
        }

        return round((float) $tagged
            ->filter(fn (ProgrammeFee $fee) => (int) $fee->feeItem->installment_tranche === 100)
            ->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);
    }

    private static function isTaggedSlice(ProgrammeFee $fee): bool
    {
        return FeeSchedule::allowsInstallmentTranche((string) ($fee->feeItem?->category ?? ''))
            && $fee->feeItem?->installment_tranche !== null;
    }
}
