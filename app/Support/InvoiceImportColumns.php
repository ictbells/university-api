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
        return ['matric_number', 'category', 'amount'];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'matric_number' => 'BUT/2019/M/0001',
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
}
