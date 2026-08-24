<?php

namespace App\Support;

class WalletImportColumns
{
    public const TYPES = ['credit', 'debit'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'matric_number',
            'type',
            'amount',
            'occurred_at',
            'description',
            'reference',
            'source_module',
        ];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return ['matric_number', 'type', 'amount', 'occurred_at'];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'matric_number' => 'BUT/2019/M/0001',
            'type' => 'credit',
            'amount' => '50000',
            'occurred_at' => '2023-09-01 10:00:00',
            'description' => 'Opening wallet credit',
            'reference' => 'WLT-LEG-0001',
            'source_module' => 'legacy_import',
        ];
    }
}
