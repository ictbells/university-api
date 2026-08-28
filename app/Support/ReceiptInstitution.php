<?php

namespace App\Support;

use App\Models\Campus;
use App\Models\Setting;

class ReceiptInstitution
{
    /**
     * @return array{name: string, motto: string, office: string, address: string, contact: string}
     */
    public static function details(): array
    {
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->first()
            ?? Campus::query()->orderBy('id')->first();

        return [
            'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
            'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
            'office' => (string) Setting::getValue('bursary_office_title', 'Bursary Department'),
            'address' => trim(collect([
                $campus?->address,
                $campus?->city,
            ])->filter()->implode(', '))
                ?: 'KM 8, Idiroko Road, Benja Village, P.M.B 1015, Ota, Ogun State',
            'contact' => (string) Setting::getValue('university_contact', 'Telephone: 07087138753'),
        ];
    }
}
