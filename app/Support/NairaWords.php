<?php

namespace App\Support;

class NairaWords
{
    public static function phrase(float $amount, bool $only = true): string
    {
        $cents = (int) round($amount * 100);
        if ($cents <= 0) {
            return $only ? 'Zero Naira Only' : 'Zero Naira';
        }

        $naira = intdiv($cents, 100);
        $kobo = $cents % 100;
        $parts = [];
        if ($naira > 0) {
            $parts[] = self::number($naira).' Naira';
        }
        if ($kobo > 0) {
            $parts[] = self::number($kobo).' Kobo';
        }

        $text = implode(' and ', $parts);

        return $only ? $text.' Only' : $text;
    }

    public static function number(int $number): string
    {
        $ones = [
            '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen',
        ];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number < 20) {
            return $ones[$number];
        }
        if ($number < 100) {
            return trim($tens[intdiv($number, 10)].' '.$ones[$number % 10]);
        }
        if ($number < 1000) {
            $rest = $number % 100;

            return trim($ones[intdiv($number, 100)].' Hundred'.($rest ? ' and '.self::number($rest) : ''));
        }
        if ($number < 1000000) {
            $rest = $number % 1000;
            $thousands = self::number(intdiv($number, 1000)).' Thousand';

            return trim($thousands.($rest ? ($rest < 100 ? ' and ' : ' ').self::number($rest) : ''));
        }
        if ($number < 1000000000) {
            $rest = $number % 1000000;
            $millions = self::number(intdiv($number, 1000000)).' Million';

            return trim($millions.($rest ? ' '.self::number($rest) : ''));
        }

        $rest = $number % 1000000000;
        $billions = self::number(intdiv($number, 1000000000)).' Billion';

        return trim($billions.($rest ? ' '.self::number($rest) : ''));
    }
}
