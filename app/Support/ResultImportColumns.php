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
            ],
            [
                'matric' => 'BUT/2024/002',
                'ca' => '',
                'exam' => '',
                'score' => '72',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function instructions(): array
    {
        return [
            'Upload Score (spreadsheet / CSV)',
            '',
            '1. Select the course offering on the Upload Score page, then upload this file or paste CSV.',
            '2. Keep the header row on the Results sheet. One row is one student (matric + scores).',
            '3. matric is required (matric_number is also accepted). The student does not need to be registered yet — unregistered scores are stored as drafts and held until they register.',
            '4. Use ca and exam together for continuous assessment and exam, or score (or total) for a single mark.',
            '5. When only score/total is provided, the page setting “Score column maps to” sends it to Total, CA, or Exam.',
            '6. Choose sitting (main or supplementary) on the Upload Score page. It applies to every row in the file.',
            '7. Letter grades are assigned from the grading scale. Do not put them in the file.',
            '8. Do not rename the header row. Extra columns are ignored.',
            '',
            'Required columns: '.implode(', ', self::required()).'.',
        ];
    }
}
