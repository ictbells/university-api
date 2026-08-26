<?php

namespace App\Services;

use App\Models\CandidateData;
use App\Support\CandidateDataImportColumns;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateDataImportService
{
    /** @var array<string, list<string>> */
    private const COLUMN_MAP = [
        'rg_num' => ['rg_num', 'registration_number', 'reg_num', 'registration number', 'reg num', 'rg num'],
        'rg_candname' => ['rg_candname', 'candidate_name', 'name', 'candname', 'candidate name', 'candidate', 'rg candname'],
        'rg_sex' => ['rg_sex', 'sex', 'gender', 'rg sex'],
        'state_name' => ['state_name', 'state', 'state name'],
        'rg_aggr' => ['rg_aggr', 'aggregate', 'aggr', 'aggregate score', 'rg_aggregate', 'rg aggregate', 'rgaggregate'],
        'co_name' => ['co_name', 'country', 'county', 'course', 'course name', 'co name'],
        'lga_name' => ['lga_name', 'lga', 'local_government', 'local government', 'lga name'],
        'subject1' => ['subject1', 'subject_1', 'subject 1', 'subj1', 'subj 1'],
        'rg_sub1scor' => [
            'rg_sub1scor', 'sub1scor', 'subject1_score', 'sub1_score',
            'subject 1 score', 'sub1 score', 'subject1score', 'sub1score',
            'score1', 'score 1', 'rg_sub1score', 'rg sub1score', 'rgsub1score', 'rg_sub1_scor',
        ],
        'subject2' => ['subject2', 'subject_2', 'subject 2', 'subj2', 'subj 2'],
        'rg_sub2scor' => [
            'rg_sub2scor', 'sub2scor', 'subject2_score', 'sub2_score',
            'subject 2 score', 'sub2 score', 'subject2score', 'sub2score',
            'score2', 'score 2', 'rg_sub2score', 'rg sub2score', 'rgsub2score',
            'rg_sub2s', 'rg_sub2_s', 'rg sub2s', 'rgsub2s',
        ],
        'subject3' => ['subject3', 'subject_3', 'subject 3', 'subj3', 'subj 3'],
        'rg_sub3scor' => [
            'rg_sub3scor', 'sub3scor', 'subject3_score', 'sub3_score',
            'subject 3 score', 'sub3 score', 'subject3score', 'sub3score',
            'score3', 'score 3', 'rg_sub3score', 'rg sub3score', 'rgsub3score',
            'i_sub3score', 'i_sub3_score', 'i sub3score', 'isub3score',
        ],
        'eng_score' => [
            'engscore', 'english_score', 'eng_score', 'english score',
            'english', 'eng score', 'englishscore',
        ],
        'subj' => ['subj', 'subject4', 'subject_4', 'subject 4', 'subj4', 'subj 4'],
    ];

    private const NUMERIC_FIELDS = ['rg_aggr', 'rg_sub1scor', 'rg_sub2scor', 'rg_sub3scor', 'eng_score'];

    public function template(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            CandidateDataImportColumns::SHEET,
            CandidateDataImportColumns::all(),
            CandidateDataImportColumns::instructions(),
            CandidateDataImportColumns::sample(),
            CandidateDataImportColumns::FILENAME,
        );
    }

    /**
     * @return array{imported: int, skipped: int, total_rows: int}
     */
    public function import(UploadedFile $file, string $academicYear): array
    {
        $rows = $this->readRows($file);
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must contain at least a header row and one data row.');
        }

        $headers = array_map(static fn ($value) => strtolower(trim((string) $value)), $rows[0]);
        $columnIndices = $this->resolveColumnIndices($headers);

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $columnIndices, $academicYear, &$imported, &$skipped) {
            for ($i = 1, $count = count($rows); $i < $count; $i++) {
                $row = $rows[$i];
                if (empty(array_filter($row, static fn ($value) => $value !== null && trim((string) $value) !== ''))) {
                    continue;
                }

                $data = $this->mapRow($row, $columnIndices);
                if (empty($data['rg_num'])) {
                    $skipped++;

                    continue;
                }

                $data['academic_year'] = $academicYear;
                $data['rg_num'] = strtoupper(str_replace(' ', '', (string) $data['rg_num']));

                CandidateData::query()->updateOrCreate(
                    [
                        'rg_num' => $data['rg_num'],
                        'academic_year' => $academicYear,
                    ],
                    $data,
                );

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'total_rows' => count($rows) - 1,
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());

            return $spreadsheet->getActiveSheet()->toArray();
        } catch (ReaderException $e) {
            throw new \InvalidArgumentException('Failed to read spreadsheet: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function resolveColumnIndices(array $headers): array
    {
        $columnIndices = [];

        foreach (self::COLUMN_MAP as $dbField => $possibleNames) {
            $found = false;
            foreach ($possibleNames as $name) {
                $index = array_search(strtolower(trim($name)), $headers, true);
                if ($index !== false) {
                    $columnIndices[$dbField] = $index;
                    $found = true;
                    break;
                }
            }

            if ($found || ! in_array($dbField, ['rg_sub2scor', 'rg_sub3scor'], true)) {
                continue;
            }

            foreach ($headers as $headerIndex => $header) {
                if (in_array($headerIndex, $columnIndices, true)) {
                    continue;
                }

                if ($dbField === 'rg_sub2scor' && (
                    str_contains($header, 'sub2') ||
                    str_contains($header, 'subject2') ||
                    (str_contains($header, 'rg') && str_contains($header, 'sub2'))
                )) {
                    $columnIndices[$dbField] = $headerIndex;
                    break;
                }

                if ($dbField === 'rg_sub3scor' && (
                    str_contains($header, 'sub3') ||
                    str_contains($header, 'subject3') ||
                    (str_contains($header, 'sub3') && str_contains($header, 'score'))
                )) {
                    $columnIndices[$dbField] = $headerIndex;
                    break;
                }
            }
        }

        return $columnIndices;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columnIndices
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $columnIndices): array
    {
        $data = [];

        foreach ($columnIndices as $dbField => $colIndex) {
            $value = $row[$colIndex] ?? null;

            if (in_array($dbField, self::NUMERIC_FIELDS, true)) {
                $data[$dbField] = $this->normalizeNumeric($value);

                continue;
            }

            if ($value === null) {
                $data[$dbField] = null;

                continue;
            }

            $value = trim((string) $value);
            $data[$dbField] = $value === '' ? null : $value;
        }

        return $data;
    }

    private function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;

        return is_finite($floatValue) ? $floatValue : null;
    }
}
