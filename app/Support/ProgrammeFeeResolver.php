<?php

namespace App\Support;

use App\Models\ProgrammeFee;
use App\Models\Student;
use Illuminate\Support\Collection;

class ProgrammeFeeResolver
{
    /**
     * @param  string|list<string>|null  $levelCode
     * @return Collection<int, ProgrammeFee>
     */
    public static function forProgram(
        int $programId,
        string|array|null $levelCode = null,
        ?string $semester = null,
    ): Collection {
        $query = ProgrammeFee::query()
            ->with('feeItem')
            ->where('program_id', $programId)
            ->where('is_active', true)
            ->whereHas('feeItem', fn ($fee) => $fee->where('is_active', true));

        $codes = self::normalizeLevelCodes($levelCode);
        if ($codes !== []) {
            $query->where(function ($builder) use ($codes) {
                $builder->where('level_code', 'all');
                foreach ($codes as $code) {
                    $builder->orWhere('level_code', $code);
                }
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
        $student->loadMissing(['program', 'application']);
        if (! $student->program_id) {
            return collect();
        }

        $codes = StudentAcademicLevel::feeLevelCodes($student);
        $lines = self::forProgram((int) $student->program_id, $codes, $semester);

        // When JUPEB (or any track) has a named academic level with its own fee rows,
        // prefer those over the shared numeric band (e.g. "100") so UG-style lines
        // accidentally left on a JUPEB programme do not inflate the bill.
        $preferred = self::preferredTrackLevelCodes($student);
        if ($preferred === []) {
            return $lines;
        }

        $named = $lines->filter(
            fn (ProgrammeFee $fee) => $fee->level_code === 'all' || in_array((string) $fee->level_code, $preferred, true)
        );
        if ($named->contains(fn (ProgrammeFee $fee) => $fee->level_code !== 'all')) {
            return $named->values();
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function preferredTrackLevelCodes(Student $student): array
    {
        $level = StudentAcademicLevel::resolve($student);
        if (! $level) {
            return [];
        }

        $preferred = [];
        $code = trim((string) ($level->code ?: ''));
        $name = trim((string) ($level->name ?: ''));
        if ($code !== '') {
            $preferred[] = $code;
        }
        if ($name !== '') {
            $preferred[] = $name;
        }

        // Numeric-only codes (100) are the shared UG band — not "preferred" for disambiguation.
        return array_values(array_filter(
            $preferred,
            fn (string $value) => ! preg_match('/^\d{3}$/', $value),
        ));
    }

    public static function totalForProgram(
        int $programId,
        string|array|null $levelCode = null,
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
            fn (ProgrammeFee $fee) => in_array((int) $fee->effective_installment_tranche, [1, 2, 3, 4], true)
        );
        if ($slices->isNotEmpty()) {
            $untagged = $lines->reject(fn (ProgrammeFee $fee) => self::isTaggedSlice($fee));

            return round((float) $slices->sum(fn (ProgrammeFee $fee) => $fee->effective_amount)
                + $untagged->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);
        }

        return round((float) $tagged
            ->filter(fn (ProgrammeFee $fee) => (int) $fee->effective_installment_tranche === 100)
            ->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);
    }

    private static function isTaggedSlice(ProgrammeFee $fee): bool
    {
        return FeeSchedule::allowsInstallmentTranche((string) ($fee->feeItem?->category ?? ''))
            && $fee->effective_installment_tranche !== null;
    }

    /**
     * @param  string|list<string>|null  $levelCode
     * @return list<string>
     */
    private static function normalizeLevelCodes(string|array|null $levelCode): array
    {
        if ($levelCode === null || $levelCode === '') {
            return [];
        }

        $raw = is_array($levelCode) ? $levelCode : [$levelCode];
        $codes = [];
        foreach ($raw as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }
}
