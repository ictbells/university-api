<?php

namespace App\Support;

use App\Models\ProgrammeFee;
use App\Models\Student;
use App\Support\BillingCurrency;
use Illuminate\Support\Collection;

class ProgrammeFeeResolver
{
    /** @var array<string, Collection<int, ProgrammeFee>> */
    private static array $programCache = [];

    /** @var array<int, Collection<int, ProgrammeFee>> */
    private static array $allFeesByProgram = [];

    /** @var array<string, float> */
    private static array $studentTotalCache = [];

    public static function flushCache(): void
    {
        self::$programCache = [];
        self::$allFeesByProgram = [];
        self::$studentTotalCache = [];
    }

    /**
     * Prefetch all active fee lines for the given programmes (one query).
     * Subsequent forProgram/totalForStudent calls filter in memory.
     *
     * @param  list<int|string|null>  $programIds
     */
    public static function warmPrograms(array $programIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => (int) $id,
            $programIds,
        ), static fn (int $id) => $id > 0)));
        $missing = array_values(array_filter(
            $ids,
            static fn (int $id) => ! isset(self::$allFeesByProgram[$id]),
        ));
        if ($missing === []) {
            return;
        }

        $grouped = ProgrammeFee::query()
            ->with('feeItem')
            ->whereIn('program_id', $missing)
            ->where('is_active', true)
            ->whereHas('feeItem', fn ($fee) => $fee->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ProgrammeFee $fee) => (int) $fee->program_id);

        foreach ($missing as $id) {
            self::$allFeesByProgram[$id] = $grouped->get($id, collect())->values();
        }
    }

    /**
     * @param  string|list<string>|null  $levelCode
     * @return Collection<int, ProgrammeFee>
     */
    public static function forProgram(
        int $programId,
        string|array|null $levelCode = null,
        ?string $semester = null,
        ?string $entryMode = null,
    ): Collection {
        $codes = self::normalizeLevelCodes($levelCode);
        $mode = self::normalizeEntryMode($entryMode);
        $cacheKey = $programId.'|'.implode(',', $codes).'|'.(string) $semester.'|'.(string) $mode;
        if (isset(self::$programCache[$cacheKey])) {
            return self::$programCache[$cacheKey];
        }

        if (! isset(self::$allFeesByProgram[$programId])) {
            self::warmPrograms([$programId]);
        }

        $lines = self::$allFeesByProgram[$programId] ?? collect();
        if ($codes !== []) {
            $codeSet = array_fill_keys($codes, true);
            $lines = $lines->filter(
                static fn (ProgrammeFee $fee) => $fee->level_code === 'all'
                    || isset($codeSet[(string) $fee->level_code])
            )->values();
        }

        if ($semester !== null && $semester !== '' && $semester !== 'both') {
            $lines = $lines->filter(
                static fn (ProgrammeFee $fee) => $fee->semester === $semester || $fee->semester === 'both'
            )->values();
        }

        $lines = self::filterByEntryMode($lines, $mode);

        return self::$programCache[$cacheKey] = $lines;
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
        $entryMode = self::studentEntryMode($student);
        $lines = self::forProgram((int) $student->program_id, $codes, $semester, $entryMode);

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

    public static function studentEntryMode(Student $student): ?string
    {
        $student->loadMissing('application');
        $mode = strtolower(trim((string) ($student->application?->entry_mode ?? '')));

        return $mode !== '' ? $mode : null;
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
        ?string $entryMode = null,
    ): float {
        return self::scheduleFullAmount(self::forProgram($programId, $levelCode, $semester, $entryMode));
    }

    public static function totalForStudent(Student $student, ?string $semester = null): float
    {
        $currency = BillingCurrency::forStudent($student);
        $entryMode = self::studentEntryMode($student) ?: '';
        $cacheKey = (int) $student->id.'|'.(string) $semester.'|'.(string) $student->program_id.'|'.(string) $student->current_level.'|'.$currency.'|'.$entryMode;
        if (array_key_exists($cacheKey, self::$studentTotalCache)) {
            return self::$studentTotalCache[$cacheKey];
        }

        return self::$studentTotalCache[$cacheKey] = self::scheduleFullAmount(self::forStudent($student, $semester), $currency);
    }

    /**
     * Full-session schedule total. When finance tags installment tranches, sum the
     * 1st–4th 25% slices (not the optional 100% pay-at-once package) so totals are not doubled.
     *
     * @param  Collection<int, ProgrammeFee>  $lines
     */
    public static function scheduleFullAmount(Collection $lines, string $currency = BillingCurrency::NGN): float
    {
        $amountOf = fn (ProgrammeFee $fee) => BillingCurrency::isUsd($currency)
            ? $fee->pricedAmount($currency)
            : $fee->effective_amount;
        $tagged = $lines->filter(fn (ProgrammeFee $fee) => self::isTaggedSlice($fee));
        if ($tagged->isEmpty()) {
            return round((float) $lines->sum($amountOf), 2);
        }

        $slices = $tagged->filter(
            fn (ProgrammeFee $fee) => in_array((int) $fee->effective_installment_tranche, [1, 2, 3, 4], true)
        );
        if ($slices->isNotEmpty()) {
            $untagged = $lines->reject(fn (ProgrammeFee $fee) => self::isTaggedSlice($fee));

            return round((float) $slices->sum($amountOf) + $untagged->sum($amountOf), 2);
        }

        return round((float) $tagged
            ->filter(fn (ProgrammeFee $fee) => (int) $fee->effective_installment_tranche === 100)
            ->sum($amountOf), 2);
    }

    private static function isTaggedSlice(ProgrammeFee $fee): bool
    {
        return FeeSchedule::allowsInstallmentTranche((string) ($fee->feeItem?->category ?? ''))
            && $fee->effective_installment_tranche !== null;
    }

    /**
     * @param  Collection<int, ProgrammeFee>  $lines
     * @return Collection<int, ProgrammeFee>
     */
    private static function filterByEntryMode(Collection $lines, ?string $mode): Collection
    {
        if ($mode === null || $mode === '') {
            return $lines->values();
        }

        if ($mode === 'all') {
            return $lines->filter(
                static fn (ProgrammeFee $fee) => self::lineEntryMode($fee) === 'all'
            )->values();
        }

        $matching = $lines->filter(function (ProgrammeFee $fee) use ($mode) {
            $lineMode = self::lineEntryMode($fee);

            return $lineMode === 'all' || $lineMode === $mode;
        })->values();

        $specific = $matching->filter(
            static fn (ProgrammeFee $fee) => self::lineEntryMode($fee) === $mode
        );
        if ($specific->isNotEmpty()) {
            return $specific->values();
        }

        return $matching;
    }

    private static function lineEntryMode(ProgrammeFee $fee): string
    {
        $mode = strtolower(trim((string) ($fee->entry_mode ?? 'all')));

        return $mode !== '' ? $mode : 'all';
    }

    private static function normalizeEntryMode(?string $entryMode): ?string
    {
        if ($entryMode === null) {
            return null;
        }
        $mode = strtolower(trim($entryMode));
        if ($mode === '') {
            return null;
        }

        return $mode;
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
