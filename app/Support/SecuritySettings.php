<?php

namespace App\Support;

use App\Models\Setting;

class SecuritySettings
{
    public const TWO_FACTOR = 'security.two_factor_enabled';

    public const PASSWORD_ROTATION_DAYS = 'security.password_rotation_days';

    public const INACTIVITY_LOGOUT_MINUTES = 'security.inactivity_logout_minutes';

    public static function defaults(): array
    {
        return [
            'two_factor_enabled' => false,
            'password_rotation_days' => 0,
            'inactivity_logout_minutes' => 0,
        ];
    }

    public static function all(): array
    {
        return [
            'two_factor_enabled' => self::twoFactorEnabled(),
            'password_rotation_days' => self::passwordRotationDays(),
            'inactivity_logout_minutes' => self::inactivityLogoutMinutes(),
        ];
    }

    public static function twoFactorEnabled(): bool
    {
        return Setting::getValue(self::TWO_FACTOR, '0') === '1';
    }

    public static function passwordRotationDays(): int
    {
        return max(0, (int) Setting::getValue(self::PASSWORD_ROTATION_DAYS, 0));
    }

    public static function inactivityLogoutMinutes(): int
    {
        return max(0, (int) Setting::getValue(self::INACTIVITY_LOGOUT_MINUTES, 0));
    }

    public static function update(array $data): array
    {
        if (array_key_exists('two_factor_enabled', $data)) {
            Setting::setValue(self::TWO_FACTOR, $data['two_factor_enabled'] ? '1' : '0');
        }
        if (array_key_exists('password_rotation_days', $data)) {
            Setting::setValue(self::PASSWORD_ROTATION_DAYS, (string) max(0, (int) $data['password_rotation_days']));
        }
        if (array_key_exists('inactivity_logout_minutes', $data)) {
            Setting::setValue(self::INACTIVITY_LOGOUT_MINUTES, (string) max(0, (int) $data['inactivity_logout_minutes']));
        }

        return self::all();
    }
}
