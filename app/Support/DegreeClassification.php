<?php

namespace App\Support;

class DegreeClassification
{
    public const OPTIONS = [
        'first' => 'First Class',
        'second_upper' => 'Second Class Upper',
        'second_lower' => 'Second Class Lower',
        'third' => 'Third Class',
        'pass' => 'Pass',
        'distinction' => 'Distinction',
        'merit' => 'Merit',
        'other' => 'Other',
    ];

    public const AWARD_LEVELS = [
        'bachelor' => 'Bachelor',
        'pgd' => 'Postgraduate diploma',
        'masters' => 'Masters',
        'other' => 'Other',
    ];

    /** @var array<string, int> */
    public const RANK = [
        'first' => 70,
        'distinction' => 60,
        'second_upper' => 60,
        'merit' => 50,
        'second_lower' => 50,
        'third' => 40,
        'pass' => 30,
        'other' => 20,
    ];

    public static function label(?string $key): string
    {
        return self::OPTIONS[$key ?? ''] ?? ($key ?: '—');
    }

    public static function rank(?string $key): int
    {
        return self::RANK[$key ?? ''] ?? 0;
    }

    public static function awardLabel(?string $key): string
    {
        return self::AWARD_LEVELS[$key ?? ''] ?? ($key ?: '—');
    }
}
