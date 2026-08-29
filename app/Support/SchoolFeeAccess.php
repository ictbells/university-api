<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\User;
use RuntimeException;

/** School fees (programme schedule) open only after physical clearance creates the student record. */
final class SchoolFeeAccess
{
    public const BLOCKED_MESSAGE = 'Complete physical clearance on campus before paying school fees.';

    public static function invoiceIsSchoolFee(Invoice $invoice): bool
    {
        $category = (string) $invoice->category;

        return $category === 'tuition' || FeeSchedule::isScheduleCategory($category);
    }

    public static function userMayPay(User $user): bool
    {
        return (bool) $user->student;
    }

    public static function assertUserMayPay(User $user): void
    {
        if (! self::userMayPay($user)) {
            throw new RuntimeException(self::BLOCKED_MESSAGE);
        }
    }

    public static function assertCanPayInvoice(User $user, Invoice $invoice): void
    {
        if (! self::invoiceIsSchoolFee($invoice)) {
            return;
        }

        self::assertUserMayPay($user);
    }
}
