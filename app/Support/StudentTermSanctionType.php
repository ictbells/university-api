<?php

namespace App\Support;

final class StudentTermSanctionType
{
    public const RUSTICATED = 'rusticated';

    public const EXPELLED = 'expelled';

    public const SUSPENDED = 'suspended';

    public const WITHDRAWN = 'withdrawn';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::RUSTICATED, self::EXPELLED, self::SUSPENDED, self::WITHDRAWN];
    }

    public static function studentshipStatus(string $type): string
    {
        return match ($type) {
            self::RUSTICATED => Studentship::STATUS_RUSTICATED,
            self::EXPELLED => Studentship::STATUS_EXPELLED,
            self::SUSPENDED => Studentship::STATUS_SUSPENDED,
            self::WITHDRAWN => Studentship::STATUS_WITHDRAWN,
            default => Studentship::STATUS_ACTIVE,
        };
    }

    public static function fromStudentship(?string $status): ?string
    {
        return match ($status) {
            Studentship::STATUS_RUSTICATED => self::RUSTICATED,
            Studentship::STATUS_EXPELLED => self::EXPELLED,
            Studentship::STATUS_SUSPENDED => self::SUSPENDED,
            Studentship::STATUS_WITHDRAWN => self::WITHDRAWN,
            default => null,
        };
    }
}
