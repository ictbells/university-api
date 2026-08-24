<?php

namespace App\Support;

class FeeSchedule
{
    public const INSTALLMENT_PERCENTS = [25, 50, 75, 100];

    public const SEMESTERS = ['first', 'second', 'both'];

    /**
     * Catalog line categories that can be assigned to programmes (SMS-style).
     *
     * @return list<string>
     */
    public static function scheduleCategories(): array
    {
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
     * Operational fees managed in the catalog but not assigned per programme.
     *
     * @return list<string>
     */
    public static function operationalCategories(): array
    {
        return [
            'acceptance_fee',
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
        return array_values(array_filter(
            self::categories(),
            fn (string $category) => $category !== 'application_fee'
        ));
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
        return in_array($category, self::scheduleCategories(), true);
    }
}
