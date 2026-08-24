<?php

namespace App\Support;

use App\Models\Setting;

class AdmissionsContactSettings
{
    public const EMAIL = 'admissions.email';

    public const PHONE = 'admissions.phone';

    public static function defaults(): array
    {
        return [
            'admissions_email' => '',
            'admissions_phone' => '',
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();

        return [
            'admissions_email' => trim((string) Setting::getValue(self::EMAIL, $defaults['admissions_email'])),
            'admissions_phone' => trim((string) Setting::getValue(self::PHONE, $defaults['admissions_phone'])),
        ];
    }

    public static function update(array $data): array
    {
        $current = self::all();
        if (array_key_exists('admissions_email', $data)) {
            $current['admissions_email'] = trim((string) $data['admissions_email']);
        }
        if (array_key_exists('admissions_phone', $data)) {
            $current['admissions_phone'] = trim((string) $data['admissions_phone']);
        }

        Setting::setValue(self::EMAIL, $current['admissions_email']);
        Setting::setValue(self::PHONE, $current['admissions_phone']);
        Setting::setValue(
            'university_contact',
            collect([$current['admissions_email'], $current['admissions_phone']])->filter()->implode(' · ')
        );

        return $current;
    }
}
