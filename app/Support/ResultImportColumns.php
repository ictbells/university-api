<?php

namespace App\Support;

class ResultImportColumns
{
    public const SHEET = 'Results';

    public const FILENAME = 'results-import-template.xlsx';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'matric',
            'ca',
            'exam',
            'score',
            'letter',
            'sitting',
        ];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return ['matric'];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function samples(): array
    {
        return [
            [
                'matric' => 'BUT/2024/001',
                'ca' => '28',
                'exam' => '44',
                'score' => '',
                'letter' => '',
                'sitting' => 'main',
            ],
            [
                'matric' => 'BUT/2024/002',
                'ca' => '',
                'exam' => '',
                'score' => '72',
                'letter' => '',
                'sitting' => 'main',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function instructions(): array
    {
        return [
            'Results CSV / spreadsheet import',
            '',
            '1. Select the course offering on the CSV import page, then upload this file or paste CSV.',
            '2. Keep the header row on the Results sheet. One row is one enrolled student.',
            '3. matric is required (matric_number is also accepted). The student must already be registered on that offering.',
            '4. Use ca and exam together for continuous assessment and exam, or score (or total) for a single mark.',
            '5. When only score/total is provided, the page setting “Score column maps to” sends it to Total, CA, or Exam.',
            '6. letter is optional. Leave it blank to let the grading scale assign the letter from the total.',
            '7. sitting is optional: main or supplementary. Default is main.',
            '8. Do not rename the header row. Extra columns are ignored.',
            '',
            'Required columns: '.implode(', ', self::required()).'.',
        ];
    }
}
