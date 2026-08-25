<?php

namespace App\Support;

use App\Models\FeeCategory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FeeSchedule
{
    public const INSTALLMENT_PERCENTS = [25, 50, 75, 100];

    public const SEMESTERS = ['first', 'second', 'both'];

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
            'sundry',
            'course_registration_extension',
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
        return in_array($category, ['application_fee', 'acceptance_fee'], true);
    }

    public static function walletAllowed(string $category): bool
    {
        return ! self::walletBlocked($category);
    }

    public static function onlinePaymentAllowed(string $category): bool
    {
        return in_array($category, ['application_fee', 'acceptance_fee'], true);
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
            'medical' => 'Medical / clinic',
            'sports' => 'Sports',
            'ict' => 'ICT',
            'laboratory' => 'Laboratory',
            'development' => 'Development levy',
            'hostel' => 'Hostel',
            'sundry' => 'Sundry',
            'course_registration_extension' => 'Course registration extension',
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
