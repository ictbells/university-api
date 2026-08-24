<?php

namespace App\Support;

use App\Models\Setting;

class ClinicSettings
{
    public const NHIS_ENABLED = 'clinic.nhis_enabled';

    public const NHIS_DEFAULT_COVERAGE_PERCENT = 'clinic.nhis_default_coverage_percent';

    public const NHIS_AUTO_COVER_LINES = 'clinic.nhis_auto_cover_lines';

    public static function defaults(): array
    {
        return [
            'nhis_enabled' => true,
            'nhis_default_coverage_percent' => 90.0,
            'nhis_auto_cover_lines' => true,
        ];
    }

    public static function all(): array
    {
        return [
            'nhis_enabled' => self::nhisEnabled(),
            'nhis_default_coverage_percent' => self::nhisDefaultCoveragePercent(),
            'nhis_auto_cover_lines' => self::nhisAutoCoverLines(),
        ];
    }

    public static function nhisEnabled(): bool
    {
        return Setting::getValue(self::NHIS_ENABLED, '1') === '1';
    }

    public static function nhisDefaultCoveragePercent(): float
    {
        return max(0, min(100, (float) Setting::getValue(self::NHIS_DEFAULT_COVERAGE_PERCENT, 90)));
    }

    public static function nhisAutoCoverLines(): bool
    {
        return Setting::getValue(self::NHIS_AUTO_COVER_LINES, '1') === '1';
    }

    public static function update(array $data): array
    {
        if (array_key_exists('nhis_enabled', $data)) {
            Setting::setValue(self::NHIS_ENABLED, $data['nhis_enabled'] ? '1' : '0');
        }
        if (array_key_exists('nhis_default_coverage_percent', $data)) {
            $pct = max(0, min(100, (float) $data['nhis_default_coverage_percent']));
            Setting::setValue(self::NHIS_DEFAULT_COVERAGE_PERCENT, (string) $pct);
        }
        if (array_key_exists('nhis_auto_cover_lines', $data)) {
            Setting::setValue(self::NHIS_AUTO_COVER_LINES, $data['nhis_auto_cover_lines'] ? '1' : '0');
        }

        return self::all();
    }
}
