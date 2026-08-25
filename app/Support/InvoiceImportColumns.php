<?php

namespace App\Support;

class InvoiceImportColumns
{
    public const PAYMENT_METHODS = ['legacy_import', 'bank_transfer', 'cash', 'pos', 'paystack'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'matric_number',
            'application_number',
            'jamb_registration',
            'invoice_number',
            'category',
            'session_label',
            'semester',
            'installment_percent',
            'amount',
            'full_amount',
            'description',
            'paid_amount',
            'payment_date',
            'payment_method',
            'payment_reference',
        ];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return ['category', 'amount'];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return self::tuitionSample();
    }

    /**
     * @return list<array<string, string>>
     */
    public static function samples(): array
    {
        return [
            self::tuitionSample(),
            self::applicationFeeSample(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tuitionSample(): array
    {
        return [
            'matric_number' => 'BUT/2019/M/0001',
            'application_number' => '',
            'jamb_registration' => '',
            'invoice_number' => '',
            'category' => 'tuition',
            'session_label' => '2023/2024',
            'semester' => 'first',
            'installment_percent' => '25',
            'amount' => '150000',
            'full_amount' => '600000',
            'description' => 'Tuition 25% 2023/2024',
            'paid_amount' => '150000',
            'payment_date' => '2023-10-15',
            'payment_method' => 'legacy_import',
            'payment_reference' => 'LEG-TUI-0001',
        ];
    }

    /**
     * Sample paid application fee keyed by JAMB (typical applicant migration).
     *
     * @return array<string, string>
     */
    public static function applicationFeeSample(): array
    {
        return [
            'matric_number' => '',
            'application_number' => 'APP/2025/00001',
            'jamb_registration' => '12345678AB',
            'invoice_number' => '',
            'category' => 'application_fee',
            'session_label' => '2025/2026',
            'semester' => '',
            'installment_percent' => '',
            'amount' => '5000',
            'full_amount' => '5000',
            'description' => 'Application fee 2025/2026',
            'paid_amount' => '5000',
            'payment_date' => '2025-01-10',
            'payment_method' => 'legacy_import',
            'payment_reference' => 'LEG-APP-0001',
        ];
    }

    /**
     * Lookup sheet for valid category values.
     *
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    public static function categoriesSheet(): array
    {
        $rows = [];
        foreach (FeeSchedule::categories() as $code) {
            $note = match ($code) {
                'application_fee' => 'Applicant migration: use application_number or jamb_registration. Import applicants posts matching paid rows.',
                'acceptance_fee' => 'Acceptance / offer fee.',
                'tuition' => 'Requires installment_percent: 25, 50, 75, or 100.',
                'hostel' => 'Hostel charges.',
                'sundry' => 'Operational / miscellaneous catalog items.',
                default => FeeSchedule::isScheduleCategory($code)
                    ? 'Programme schedule category.'
                    : 'Fee catalog / operational category.',
            };
            $rows[] = [$code, FeeSchedule::label($code), $note];
        }

        return [
            'title' => 'Categories',
            'headers' => ['category', 'label', 'notes'],
            'rows' => $rows,
        ];
    }
}
