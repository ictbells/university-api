<?php

namespace App\Support;

class CandidateDataImportColumns
{
    public const SHEET = 'Candidates';

    public const FILENAME = 'candidate-data-import-template.xlsx';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'registration_number',
            'candidate_name',
            'sex',
            'state',
            'lga',
            'aggregate',
            'course',
            'english_score',
            'subject1',
            'subject1_score',
            'subject2',
            'subject2_score',
            'subject3',
            'subject3_score',
            'subject4',
        ];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return ['registration_number'];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'registration_number' => '20261234AB',
            'candidate_name' => 'Adaeze Okoye',
            'sex' => 'F',
            'state' => 'Ogun',
            'lga' => 'Ado-Odo/Ota',
            'aggregate' => '248',
            'course' => 'Computer Engineering',
            'english_score' => '68',
            'subject1' => 'Mathematics',
            'subject1_score' => '72',
            'subject2' => 'Physics',
            'subject2_score' => '64',
            'subject3' => 'Chemistry',
            'subject3_score' => '61',
            'subject4' => '',
        ];
    }

    /**
     * @return list<string>
     */
    public static function instructions(): array
    {
        return [
            'Upload JAMB candidate rows for the application session selected on the Candidate data page (not in this file).',
            'Required column: registration_number (JAMB number). Matching numbers on the same application session are updated.',
            'Optional: candidate_name, sex, state, lga, aggregate, course, english_score, subject1–3 with scores, subject4.',
            'Do not rename the header row. Extra columns are ignored.',
        ];
    }
}
