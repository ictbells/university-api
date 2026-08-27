<?php

namespace App\Support;

use App\Models\FeeCategory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FeeSchedule
{
    public const INSTALLMENT_PERCENTS = [25, 50, 75, 100];

    /** Fee-item tranche codes: 1–4 = successive 25% slices; 100 = pay-in-full package. */
    public const INSTALLMENT_TRANCHES = [1, 2, 3, 4, 100];

    public const SEMESTERS = ['first', 'second', 'both'];

    /**
     * @return list<array{value: int, label: string, percent: int}>
     */
    public static function installmentTrancheOptions(): array
    {
        return [
            ['value' => 1, 'label' => '1st 25%', 'percent' => 25],
            ['value' => 2, 'label' => '2nd 25%', 'percent' => 50],
            ['value' => 3, 'label' => '3rd 25%', 'percent' => 75],
            ['value' => 4, 'label' => '4th 25%', 'percent' => 100],
            ['value' => 100, 'label' => 'Full 100% (pay at once)', 'percent' => 100],
        ];
    }

    public static function installmentTrancheLabel(?int $tranche): ?string
    {
        if ($tranche === null) {
            return null;
        }

        foreach (self::installmentTrancheOptions() as $option) {
            if ($option['value'] === $tranche) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * Which fee-item tranches belong on a student installment invoice.
     *
     * @return list<int>
     */
    public static function tranchesForInstallmentPercent(int $percent, bool $hasFullPackage = false): array
    {
        if ($percent === 100 && $hasFullPackage) {
            return [100];
        }

        return match ($percent) {
            25 => [1],
            50 => [1, 2],
            75 => [1, 2, 3],
            100 => [1, 2, 3, 4],
            default => [1, 2, 3, 4],
        };
    }

    /**
     * Slices already settled by a paid installment (25% covers 1st, 50% covers 1st+2nd, …).
     *
     * @return list<int>
     */
    public static function tranchesCoveredByPaidPercent(float $paidPercent): array
    {
        if ($paidPercent >= 100) {
            return [1, 2, 3, 4, 100];
        }
        if ($paidPercent >= 75) {
            return [1, 2, 3];
        }
        if ($paidPercent >= 50) {
            return [1, 2];
        }
        if ($paidPercent >= 25) {
            return [1];
        }

        return [];
    }

    /**
     * Tranche codes still unpaid for the chosen installment.
     *
     * @return list<int>
     */
    public static function remainingTranchesForInstallmentPercent(
        int $percent,
        float $paidPercent,
        bool $hasFullPackage = false,
    ): array {
        $covered = self::tranchesCoveredByPaidPercent($paidPercent);

        return array_values(array_filter(
            self::tranchesForInstallmentPercent($percent, $hasFullPackage),
            static fn (int $tranche) => ! in_array($tranche, $covered, true)
        ));
    }

    /**
     * @return list<string>
     */
    public static function scheduleCategories(): array
    {
        if (self::tableReady()) {
            return FeeCategory::query()
                ->where('is_schedule', true)
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->pluck('code')
                ->all();
        }

        return [
            'tuition',
            'library',
            'medical',
            'sports',
            'ict',
            'laboratory',
            'development',
            'other',
        ];
    }

    /**
     * @return list<string>
     */
    public static function operationalCategories(): array
    {
        if (self::tableReady()) {
            return FeeCategory::query()
                ->where('is_schedule', false)
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->pluck('code')
                ->all();
        }

        return [
            'acceptance_fee',
            'application_fee',
            'hostel',
            'clinic',
            'sundry',
            'course_registration_extension',
            'transcript',
        ];
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        if (self::tableReady()) {
            return FeeCategory::query()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->pluck('code')
                ->merge(['application_fee'])
                ->unique()
                ->values()
                ->all();
        }

        return array_values(array_unique([
            ...self::scheduleCategories(),
            ...self::operationalCategories(),
            'application_fee',
        ]));
    }

    /**
     * @return list<string>
     */
    public static function staffEditableCategories(): array
    {
        if (self::tableReady()) {
            return FeeCategory::query()
                ->staffEditable()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->pluck('code')
                ->all();
        }

        return self::categories();
    }

    /**
     * @return list<array{value: string, label: string, schedule: bool}>
     */
    public static function staffEditableCategoryOptions(): array
    {
        if (self::tableReady()) {
            return FeeCategory::query()
                ->staffEditable()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->map(fn (FeeCategory $category) => [
                    'value' => $category->code,
                    'label' => $category->name,
                    'schedule' => (bool) $category->is_schedule,
                ])
                ->all();
        }

        return collect(self::staffEditableCategories())
            ->map(fn (string $category) => [
                'value' => $category,
                'label' => self::label($category),
                'schedule' => self::isScheduleCategory($category),
            ])
            ->values()
            ->all();
    }

    public static function walletBlocked(string $category): bool
    {
        return in_array($category, ['application_fee', 'acceptance_fee', 'transcript'], true);
    }

    public static function requiresEntryMode(string $category): bool
    {
        return in_array($category, ['application_fee', 'acceptance_fee'], true);
    }

    /**
     * Programme-schedule lines (tuition, ICT, lab, infrastructure, …) can be tagged
     * 1st–4th 25% or Full 100%. Application, acceptance, and transcript fees cannot.
     */
    public static function allowsInstallmentTranche(string $category): bool
    {
        return self::isScheduleCategory($category);
    }

    public static function walletAllowed(string $category): bool
    {
        return ! self::walletBlocked($category);
    }

    /**
     * Catalog lines bursary can invoice directly (hostel, clinic, sundry, …).
     * Programme-schedule lines stay on Programme fees / student installments.
     *
     * @return list<string>
     */
    public static function staffDirectInvoiceCategories(): array
    {
        return array_values(array_filter(
            self::operationalCategories(),
            fn (string $category) => self::walletAllowed($category),
        ));
    }

    public static function onlinePaymentAllowed(string $category): bool
    {
        return in_array($category, ['application_fee', 'acceptance_fee', 'transcript'], true);
    }

    public static function label(string $category): string
    {
        if (self::tableReady()) {
            $name = FeeCategory::query()->where('code', $category)->value('name');
            if ($name) {
                return $name;
            }
        }

        return match ($category) {
            'acceptance_fee' => 'Acceptance fee',
            'tuition' => 'Tuition',
            'library' => 'Library',
            'medical' => 'Medical levy',
            'clinic' => 'Clinic services',
            'sports' => 'Sports',
            'ict' => 'ICT',
            'laboratory' => 'Laboratory',
            'development' => 'Development levy',
            'hostel' => 'Hostel',
            'sundry' => 'Sundry',
            'course_registration_extension' => 'Course registration extension',
            'transcript' => 'Official transcript',
            'other' => 'Other',
            'application_fee' => 'Application fee',
            default => ucfirst(str_replace('_', ' ', $category)),
        };
    }

    public static function isScheduleCategory(string $category): bool
    {
        if (self::tableReady()) {
            return FeeCategory::query()
                ->where('code', $category)
                ->where('is_schedule', true)
                ->exists();
        }

        return in_array($category, [
            'tuition',
            'library',
            'medical',
            'sports',
            'ict',
            'laboratory',
            'development',
            'other',
        ], true);
    }

    public static function codeFromName(string $name): string
    {
        $code = Str::slug(trim($name), '_');
        if ($code === '') {
            $code = 'fee_category';
        }

        return substr($code, 0, 60);
    }

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('fee_categories');
        } catch (\Throwable) {
            return false;
        }
    }
}
