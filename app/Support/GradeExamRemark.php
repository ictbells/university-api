<?php

namespace App\Support;

final class GradeExamRemark
{
    public const ABS_P = 'abs_p';

    public const ABS_NP = 'abs_np';

    public const SICK = 'sick';

    public const AR = 'ar';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ABS_P, self::ABS_NP, self::SICK, self::AR];
    }

    /**
     * Admin-set sitting remarks. AR is derived from registered courses with no score.
     *
     * @return list<string>
     */
    public static function adminTypes(): array
    {
        return [self::ABS_P, self::ABS_NP, self::SICK];
    }

    public static function normalize(?string $value): ?string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || $raw === 'null' || $raw === 'none') {
            return null;
        }

        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            self::ABS_P, 'abs_p', 'absp', 'absent_with_permission', 'absent with permission' => self::ABS_P,
            self::ABS_NP, 'abs_np', 'absnp', 'absent_without_permission', 'absent without permission' => self::ABS_NP,
            self::SICK, 'medical', 'ill' => self::SICK,
            self::AR, 'awaiting', 'awaiting_result', 'incomplete' => self::AR,
            default => in_array($raw, self::all(), true) ? $raw : null,
        };
    }

    public static function label(?string $value): string
    {
        return match (self::normalize($value)) {
            self::ABS_P => 'ABS_P',
            self::ABS_NP => 'ABS_NP',
            self::SICK => 'SICK',
            self::AR => 'AR',
            default => '',
        };
    }

    public static function isRecordedOutcome(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null && $normalized !== self::AR;
    }

    public static function isAwaiting(?string $value): bool
    {
        return self::normalize($value) === self::AR;
    }
}
