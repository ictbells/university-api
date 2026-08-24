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
            'exam_clearance' => ExamClearanceSettings::all(),
            ...AdmissionsContactSettings::all(),
            ...StaffSupportContactSettings::all(),
            'studentship_years_after_graduation' => Studentship::yearsAfterGraduation(),
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
        if (array_key_exists('exam_clearance', $data) && is_array($data['exam_clearance'])) {
            ExamClearanceSettings::update($data['exam_clearance']);
        }
        if (array_key_exists('admissions_email', $data) || array_key_exists('admissions_phone', $data)) {
            AdmissionsContactSettings::update($data);
        }
        if (
            array_key_exists('staff_support_label', $data)
            || array_key_exists('staff_support_email', $data)
            || array_key_exists('staff_support_phone', $data)
        ) {
            StaffSupportContactSettings::update($data);
        }
        if (array_key_exists('studentship_years_after_graduation', $data)) {
            Setting::setValue(
                Studentship::YEARS_KEY,
                (string) max(1, min(10, (int) $data['studentship_years_after_graduation'])),
            );
        }

        return self::all();
    }
}
