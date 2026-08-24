<?php

namespace App\Support;

use App\Models\Setting;

class StaffSupportContactSettings
{
    public const LABEL = 'staff_support.label';

    public const EMAIL = 'staff_support.email';

    public const PHONE = 'staff_support.phone';

    public static function defaults(): array
    {
        return [
            'staff_support_label' => 'ICT & Registry support',
            'staff_support_email' => 'ict@bellsuniversity.edu.ng',
            'staff_support_phone' => '+234 801 000 0000',
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();

        return [
            'staff_support_label' => trim((string) Setting::getValue(self::LABEL, $defaults['staff_support_label']))
                ?: $defaults['staff_support_label'],
            'staff_support_email' => trim((string) Setting::getValue(self::EMAIL, $defaults['staff_support_email'])),
            'staff_support_phone' => trim((string) Setting::getValue(self::PHONE, $defaults['staff_support_phone'])),
        ];
    }

    public static function update(array $data): array
    {
        $current = self::all();
        if (array_key_exists('staff_support_label', $data)) {
            $label = trim((string) $data['staff_support_label']);
            $current['staff_support_label'] = $label !== '' ? $label : self::defaults()['staff_support_label'];
        }
        if (array_key_exists('staff_support_email', $data)) {
            $current['staff_support_email'] = trim((string) $data['staff_support_email']);
        }
        if (array_key_exists('staff_support_phone', $data)) {
            $current['staff_support_phone'] = trim((string) $data['staff_support_phone']);
        }

        Setting::setValue(self::LABEL, $current['staff_support_label']);
        Setting::setValue(self::EMAIL, $current['staff_support_email']);
        Setting::setValue(self::PHONE, $current['staff_support_phone']);

        return $current;
    }
}
