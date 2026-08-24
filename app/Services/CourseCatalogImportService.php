<?php

namespace App\Services;

use App\Models\AcademicLevel;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseCatalogImportService
{
    public const COLUMNS = ['code', 'title', 'units', 'course_type', 'department_code', 'programme_code', 'level_code'];

    /**
     * @return array{created: int, updated: int, attached: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function import(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $rows[0]);
        $indexes = [];
        foreach (self::COLUMNS as $column) {
            $indexes[$column] = array_search($column, $header, true);
        }
        if ($indexes['code'] === false || $indexes['title'] === false) {
            throw new \InvalidArgumentException('The spreadsheet must include code and title columns.');
        }

        $created = 0;
        $updated = 0;
        $attached = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $indexes, &$created, &$updated, &$attached, &$errors) {
            for ($i = 1, $count = count($rows); $i < $count; $i++) {
                $row = $rows[$i];
                $line = $i + 1;
                if (! is_array($row) || $this->rowEmpty($row)) {
                    continue;
                }

                try {
                    $result = $this->importRow($row, $indexes);
                    $created += $result['created'];
                    $updated += $result['updated'];
                    $attached += $result['attached'];
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $line, 'message' => $e->getMessage()];
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'attached' => $attached,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->fromArray([
            ['GST 101', 'Use of English', 2, 'general', 'GST', '', ''],
            ['CPE 201', 'Introduction to Computing', 3, 'faculty', 'CPE', 'B.Eng Computer Engineering', '200'],
            ['CPE 301', 'Data Structures', 3, 'departmental', 'CPE', 'B.Eng Computer Engineering', '300'],
        ], null, 'A2');

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'course-catalogue-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int|false>  $indexes
     * @return array{created: int, updated: int, attached: int}
     */
    private function importRow(array $row, array $indexes): array
    {
        $code = strtoupper(trim((string) $this->cell($row, $indexes['code'])));
        $title = trim((string) $this->cell($row, $indexes['title']));
        if ($code === '' || $title === '') {
            throw new \InvalidArgumentException('Code and title are required.');
        }

        $type = strtolower(trim((string) $this->cell($row, $indexes['course_type']))) ?: 'departmental';
        if (! in_array($type, Course::TYPES, true)) {
            throw new \InvalidArgumentException('course_type must be general, faculty, or departmental.');
        }

        $units = (int) $this->cell($row, $indexes['units']);
        if ($units < 1) {
            $units = 3;
        }

        $departmentCode = strtoupper(trim((string) $this->cell($row, $indexes['department_code'])));
        $department = Department::query()->whereRaw('UPPER(code) = ?', [$departmentCode])->first();
        if (! $department) {
            throw new \InvalidArgumentException('Unknown department_code.');
        }

        $course = Course::query()->whereRaw('UPPER(code) = ?', [$code])->first();
        $created = 0;
        $updated = 0;
        if ($course) {
            $course->update([
                'department_id' => $department->id,
                'title' => $title,
                'units' => $units,
                'course_type' => $type,
            ]);
            $updated = 1;
        } else {
            $course = Course::query()->create([
                'department_id' => $department->id,
                'code' => $code,
                'title' => $title,
                'units' => $units,
                'course_type' => $type,
            ]);
            $created = 1;
        }

        $programmeCode = trim((string) $this->cell($row, $indexes['programme_code']));
        $levelCode = trim((string) $this->cell($row, $indexes['level_code']));
        $levelId = null;
        if ($levelCode !== '') {
            $level = AcademicLevel::query()->whereRaw('UPPER(code) = ?', [strtoupper($levelCode)])->first();
            if (! $level) {
                throw new \InvalidArgumentException('Unknown level_code.');
            }
            $levelId = $level->id;
        }

        $programs = $programmeCode === '' && $type === 'general'
            ? Program::query()->where('is_active', true)->get()
            : Program::query()->whereRaw('UPPER(code) = ?', [strtoupper($programmeCode)])->get();

        if ($programmeCode !== '' && $programs->isEmpty()) {
            throw new \InvalidArgumentException('Unknown programme_code.');
        }

        $attached = 0;
        foreach ($programs as $program) {
            $changed = $course->programs()->syncWithoutDetaching([
                $program->id => [
                    'academic_level_id' => $levelId,
                    'bucket' => $type,
                ],
            ]);
            if (($changed['attached'][0] ?? null) || ($changed['updated'][0] ?? null)) {
                $attached++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'attached' => $attached];
    }

    /** @param  list<mixed>  $row */
    private function cell(array $row, int|false $index): mixed
    {
        return $index === false ? null : ($row[$index] ?? null);
    }

    /** @param  list<mixed>  $row */
    private function rowEmpty(array $row): bool
    {
        return collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty();
    }
}
