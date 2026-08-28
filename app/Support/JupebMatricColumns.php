<?php

namespace App\Support;

class JupebMatricColumns
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return ['application_number', 'student_number', 'email', 'nin', 'matric_number'];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return ['matric_number'];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'application_number' => 'APP/2026/00001',
            'student_number' => '',
            'email' => 'jupeb.applicant@example.com',
            'nin' => '',
            'matric_number' => 'JUPEB/2026/0001',
        ];
    }

    /**
     * @return list<string>
     */
    public static function instructions(): array
    {
        return [
            'Assign JUPEB matric numbers',
            '',
            '1. Keep the header row on the Matric sheet. One row per student.',
            '2. Provide matric_number plus at least one identifier: application_number, student_number, email, or nin.',
            '3. Only JUPEB students without a matric number (or with the same number already) are updated.',
            '4. Copy identifiers from the Pending students lookup sheet. Do not paste data into Instructions or lookup sheets.',
            '5. Duplicate matric numbers are skipped.',
            '',
            'Required columns: '.implode(', ', self::required()),
        ];
    }
}
