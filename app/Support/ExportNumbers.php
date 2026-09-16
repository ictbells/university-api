<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExportNumbers
{
    public const MONEY_FORMAT = '#,##0.00';

    public const COUNT_FORMAT = '#,##0';

    /**
     * @param  list<string>  $keys
     */
    public static function isMoneyField(string $key, string $label = ''): bool
    {
        $haystack = strtolower($key.' '.$label);

        return (bool) preg_match(
            '/amount|balance|rebate|fee|gross|billed|paid|outstanding|wallet|naira|invoiced|collected|receipt/',
            $haystack,
        );
    }

    public static function toNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '—') {
            return null;
        }
        if (is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }
        if (! is_string($value)) {
            return null;
        }

        $normalized = str_replace([',', 'NGN', 'ngn', '₦'], '', $value);
        $normalized = preg_replace('/[^\d.\-]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.') {
            return null;
        }
        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    public static function display(mixed $value, int $decimals = 2): string
    {
        $number = self::toNumber($value);
        if ($number === null) {
            return $value === null || $value === '' ? '—' : (string) $value;
        }

        return number_format($number, $decimals);
    }

    public static function writeCell(Worksheet $sheet, string $cell, mixed $value, bool $numeric = false, bool $money = true): void
    {
        if ($numeric) {
            $number = self::toNumber($value);
            if ($number !== null) {
                $sheet->setCellValueExplicit($cell, $number, DataType::TYPE_NUMERIC);
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(
                    $money ? self::MONEY_FORMAT : self::COUNT_FORMAT
                );
                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                return;
            }
        }

        $sheet->setCellValue($cell, $value === null || $value === '' ? '—' : $value);
    }
}
