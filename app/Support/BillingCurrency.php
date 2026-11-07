<?php

namespace App\Support;

use App\Models\Application;
use App\Models\FeeItem;
use App\Models\Student;
use RuntimeException;

class BillingCurrency
{
    public const NGN = 'NGN';

    public const USD = 'USD';

    public const COUNTRY_NIGERIA = 'Nigeria';

    public const COUNTRY_NON_NIGERIA = 'Non-Nigeria';

    public static function normalize(?string $currency): string
    {
        return strtoupper(trim((string) $currency)) === self::USD ? self::USD : self::NGN;
    }

    public static function isUsd(?string $currency): bool
    {
        return self::normalize($currency) === self::USD;
    }

    /**
     * Postgraduate applicants outside Nigeria are billed in dollars.
     * Every other applicant and student stays in naira.
     */
    public static function fromCountry(?string $country, ?string $entryMode): string
    {
        if ($entryMode === 'pg' && $country === self::COUNTRY_NON_NIGERIA) {
            return self::USD;
        }

        return self::NGN;
    }

    public static function forApplication(?Application $application): string
    {
        if (! $application) {
            return self::NGN;
        }
        if ($application->billing_currency) {
            return self::normalize($application->billing_currency);
        }

        return self::fromCountry($application->country, $application->entry_mode);
    }

    public static function forStudent(?Student $student): string
    {
        if (! $student) {
            return self::NGN;
        }
        if ($student->billing_currency) {
            return self::normalize($student->billing_currency);
        }

        return self::NGN;
    }

    public static function feeItemAmount(FeeItem $fee, string $currency): float
    {
        if (self::isUsd($currency)) {
            $amount = round((float) ($fee->amount_usd ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException($fee->name.' has no USD amount. Set it on the fee catalog before billing a foreign student.');
            }

            return $amount;
        }

        $amount = round((float) $fee->amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException($fee->name.' amount must be greater than zero.');
        }

        return $amount;
    }

    public static function matches(?string $stored, string $currency): bool
    {
        $stored = strtoupper(trim((string) $stored));
        if ($stored === '') {
            $stored = self::NGN;
        }

        return $stored === self::normalize($currency);
    }
};
