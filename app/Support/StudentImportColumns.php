<?php

namespace App\Support;

class StudentImportColumns
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'email',
            'phone',
            'alternate_phone',
            'password',
            'nin',
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'gender',
            'marital_status',
            'religion',
            'country',
            'state',
            'lga',
            'address',
            'blood_group',
            'genotype',
            'has_medical_condition',
            'medical_condition_details',
            'next_of_kin_name',
            'next_of_kin_relationship',
            'next_of_kin_phone',
            'next_of_kin_email',
            'next_of_kin_address',
            'sponsor_name',
            'sponsor_relationship',
            'sponsor_phone',
            'sponsor_email',
            'sponsor_address',
            'programme_id',
            'matric_number',
            'current_level',
            'student_number',
            'jamb_registration',
            'old_application_number',
        ];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return [
            'email',
            'phone',
            'first_name',
            'last_name',
            'programme_id',
            'matric_number',
            'current_level',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        $row = array_fill_keys(self::all(), '');
        $row['email'] = 'ada.student@example.com';
        $row['phone'] = '08030000000';
        $row['alternate_phone'] = '08031112222';
        $row['nin'] = '12345678901';
        $row['first_name'] = 'Adaeze';
        $row['middle_name'] = 'Chioma';
        $row['last_name'] = 'Okoye';
        $row['date_of_birth'] = '2004-03-18';
        $row['gender'] = 'Female';
        $row['country'] = 'Nigeria';
        $row['programme_id'] = '1';
        $row['matric_number'] = 'BUT/2019/M/0001';
        $row['current_level'] = '200';
        $row['jamb_registration'] = '12345678AB';

        return $row;
    }
}
