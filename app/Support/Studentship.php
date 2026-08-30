<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class Studentship
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUS_ALUMNI = 'alumni';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_GRADUATED,
        self::STATUS_ALUMNI,
        self::STATUS_WITHDRAWN,
        self::STATUS_SUSPENDED,
    ];

    public const CURRENT_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_GRADUATED,
    ];

    public const YEARS_KEY = 'studentship.years_after_graduation';

    public const COMPLETED_STATUSES = [
        self::STATUS_GRADUATED,
        self::STATUS_ALUMNI,
    ];

    public const INCOMPLETE_PROGRAMME_MESSAGE = 'Complete your current programme before applying for another. Registry must confirm graduation first.';

    public static function yearsAfterGraduation(): int
    {
        return max(1, min(10, (int) Setting::getValue(self::YEARS_KEY, 2)));
    }

    public static function expiryDate(CarbonInterface $graduatedAt): Carbon
    {
        return Carbon::parse($graduatedAt)->startOfDay()->addYears(self::yearsAfterGraduation());
    }

    public static function isCurrent(Student $student): bool
    {
        if (! in_array($student->status, self::CURRENT_STATUSES, true)) {
            return false;
        }

        if (! $student->studentship_expires_at) {
            return true;
        }

        return Carbon::parse($student->studentship_expires_at)->startOfDay()->isAfter(now()->startOfDay());
    }

    public static function expire(Student $student): Student
    {
        $student->update(['status' => self::STATUS_ALUMNI]);

        return $student->fresh() ?? $student;
    }

    public static function canRegisterCourses(Student $student): bool
    {
        return $student->status === self::STATUS_ACTIVE && self::isCurrent($student);
    }

    public static function canApplyForAnotherProgramme(?Student $student): bool
    {
        if (! $student) {
            return true;
        }

        return in_array($student->status, self::COMPLETED_STATUSES, true);
    }
}
