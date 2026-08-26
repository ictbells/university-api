<?php

namespace App\Services;

use App\Models\AcademicLevel;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OlevelSubject;
use App\Models\Program;
use App\Support\ApplicantImportColumns;
use App\Support\CatalogImportColumns;
use App\Support\CatalogImportSkipped;
use App\Support\ImportLookupSheets;
use App\Support\SpreadsheetImport;
use App\Support\WorkflowCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AcademicCatalogImportService
{
    /**
     * @return array<string, mixed>
     */
    public function import(string $type, UploadedFile|string $file): array
    {
        $type = $this->assertType($type);
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $sheet = CatalogImportColumns::sheetTitle($type);
        $rows = SpreadsheetImport::readRows($path, $sheet);
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $headers = array_map(fn ($value) => SpreadsheetImport::normalizeHeader((string) $value), $rows[0]);
        $indexes = SpreadsheetImport::indexHeaders($headers);
        foreach (CatalogImportColumns::required($type) as $field) {
            if (! array_key_exists($field, $indexes)) {
                throw new \InvalidArgumentException("The spreadsheet must include a {$field} column.");
            }
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $line = $i + 1;
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            $data = SpreadsheetImport::mapRow($row, $indexes);
            try {
                DB::transaction(fn () => $this->importRow($type, $data));
                $created++;
            } catch (CatalogImportSkipped $e) {
                $skipped++;
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'type' => $type,
        ];
    }

    public function template(string $type): StreamedResponse
    {
        $type = $this->assertType($type);

        return SpreadsheetImport::templateDownload(
            CatalogImportColumns::sheetTitle($type),
            CatalogImportColumns::all($type),
            CatalogImportColumns::instructions($type),
            CatalogImportColumns::sample($type),
            CatalogImportColumns::filename($type),
            $this->lookupSheets($type),
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importRow(string $type, array $data): void
    {
        foreach (CatalogImportColumns::required($type) as $field) {
            if (blank($data[$field] ?? null)) {
                throw new RuntimeException("Missing required field: {$field}.");
            }
        }

        match ($type) {
            'colleges' => $this->importCollege($data),
            'departments' => $this->importDepartment($data),
            'programmes' => $this->importProgramme($data),
            'olevel' => $this->importOlevel($data),
            'courses' => $this->importCourse($data),
            default => throw new RuntimeException('Unknown catalogue import type.'),
        };
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importCollege(array $data): void
    {
        $campus = $this->findById(Campus::query(), $data['campus_id'], 'campus_id');
        $code = $this->normalizeCode($data['code'] ?? '');
        $name = trim($data['name']);
        if ($code !== '' && $this->findByCode(Faculty::query(), $code)) {
            throw new CatalogImportSkipped('A college with this code already exists.');
        }
        if ($code === '' && Faculty::query()
            ->where('campus_id', $campus->id)
            ->whereRaw('UPPER(name) = ?', [strtoupper($name)])
            ->exists()) {
            throw new CatalogImportSkipped('A college with this name already exists on this campus.');
        }

        Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
        ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importDepartment(array $data): void
    {
        $college = $this->findById(Faculty::query(), $data['college_id'], 'college_id');
        $code = $this->normalizeCode($data['code'] ?? '');
        $name = trim($data['name']);
        if ($code !== '' && $this->findByCode(Department::query(), $code)) {
            throw new CatalogImportSkipped('A department with this code already exists.');
        }
        if ($code === '' && Department::query()
            ->where('faculty_id', $college->id)
            ->whereRaw('UPPER(name) = ?', [strtoupper($name)])
            ->exists()) {
            throw new CatalogImportSkipped('A department with this name already exists in this college.');
        }

        Department::query()->create([
            'faculty_id' => $college->id,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
        ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importProgramme(array $data): void
    {
        $department = $this->findById(Department::query(), $data['department_id'], 'department_id');
        $code = $this->normalizeCode($data['code'] ?? '');
        $name = trim($data['name']);
        if ($code !== '' && $this->findByCode(Program::query(), $code)) {
            throw new CatalogImportSkipped('A programme with this code already exists.');
        }
        if ($code === '' && Program::query()
            ->where('department_id', $department->id)
            ->whereRaw('UPPER(name) = ?', [strtoupper($name)])
            ->exists()) {
            throw new CatalogImportSkipped('A programme with this name already exists in this department.');
        }

        $studyLevel = strtolower(trim($data['study_level']));
        if (! in_array($studyLevel, ['undergraduate', 'postgraduate'], true)) {
            throw new RuntimeException('study_level must be undergraduate or postgraduate.');
        }
        $years = (int) $data['duration_years'];
        if ($years < 1 || $years > 10) {
            throw new RuntimeException('duration_years must be between 1 and 10.');
        }
        $modes = $this->parseEntryModes($data['entry_modes']);
        $payload = [
            'department_id' => $department->id,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'award_type' => trim($data['award_type']),
            'study_level' => $studyLevel,
            'duration_years' => $years,
            'entry_modes' => $modes,
            'is_research_degree' => $this->boolish($data['is_research_degree'] ?? null),
            'is_active' => true,
        ];
        $payload['workflow_template_id'] = WorkflowCatalog::ensureDefaultId(new Program($payload));

        Program::query()->create($payload);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importOlevel(array $data): void
    {
        $code = $this->normalizeCode($data['code'] ?? '');
        $name = trim($data['name']);
        if ($code !== '' && $this->findByCode(OlevelSubject::query(), $code)) {
            throw new CatalogImportSkipped('An O’level subject with this code already exists.');
        }
        if ($code === '' && OlevelSubject::query()->whereRaw('UPPER(name) = ?', [strtoupper($name)])->exists()) {
            throw new CatalogImportSkipped('An O’level subject with this name already exists.');
        }

        OlevelSubject::query()->create([
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'is_active' => array_key_exists('is_active', $data) && $data['is_active'] !== ''
                ? $this->boolish($data['is_active'])
                : true,
        ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importCourse(array $data): void
    {
        $code = strtoupper(trim($data['code']));
        $title = trim($data['title']);
        if ($code === '' || $title === '') {
            throw new RuntimeException('code and title are required.');
        }
        if ($this->findByCode(Course::query(), $code)) {
            throw new CatalogImportSkipped('A course with this code already exists.');
        }

        $type = strtolower(trim((string) ($data['course_type'] ?? '')));
        if ($type === '' || ! in_array($type, Course::TYPES, true)) {
            throw new RuntimeException('course_type must be general, faculty, or departmental.');
        }
        $status = strtolower(trim((string) ($data['status'] ?? ''))) ?: 'core';
        if (! in_array($status, Course::STATUSES, true)) {
            throw new RuntimeException('status must be core, elective, or required.');
        }
        $units = (int) ($data['units'] ?? 0);
        if ($units < 1) {
            $units = 3;
        }
        $department = $this->findById(Department::query(), $data['department_id'], 'department_id');

        $programmeId = SpreadsheetImport::parseOptionalId($data['programme_id'] ?? '', 'programme_id');
        $levelId = SpreadsheetImport::parseOptionalId($data['level_id'] ?? '', 'level_id');
        if ($levelId !== null) {
            $this->findById(AcademicLevel::query(), (string) $levelId, 'level_id');
        }

        $programs = $programmeId !== null
            ? collect([$this->findById(Program::query(), (string) $programmeId, 'programme_id')])
            : collect();

        $course = Course::query()->create([
            'department_id' => $department->id,
            'code' => $code,
            'title' => $title,
            'units' => $units,
            'course_type' => $type,
            'status' => $status,
        ]);

        foreach ($programs as $program) {
            $course->programs()->syncWithoutDetaching([
                $program->id => [
                    'academic_level_id' => $levelId,
                    'bucket' => $type,
                ],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function parseEntryModes(string $value): array
    {
        $modes = [];
        foreach (preg_split('/[;,]/', $value) ?: [] as $part) {
            $mode = strtolower(trim($part));
            if ($mode === '') {
                continue;
            }
            if (! in_array($mode, ApplicantImportColumns::MODES, true)) {
                throw new RuntimeException("Unknown entry mode: {$mode}.");
            }
            $modes[] = $mode;
        }
        if ($modes === []) {
            throw new RuntimeException('entry_modes must include at least one of utme, de, jupeb, transfer, pg.');
        }

        return array_values(array_unique($modes));
    }

    private function findById($query, string $value, string $field): mixed
    {
        $model = $query->find(SpreadsheetImport::parseId($value, $field));
        if (! $model) {
            throw new RuntimeException("Unknown {$field}.");
        }

        return $model;
    }

    private function findByCode($query, string $code): mixed
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $query->whereRaw('UPPER(REPLACE(COALESCE(code, ""), " ", "")) = ?', [$code])->first();
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(str_replace(' ', '', trim($code)));
    }

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @return list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    private function lookupSheets(string $type): array
    {
        return match ($type) {
            'colleges' => [ImportLookupSheets::campuses()],
            'departments' => [ImportLookupSheets::colleges()],
            'programmes' => [ImportLookupSheets::departments()],
            'olevel' => [],
            'courses' => [
                ImportLookupSheets::departments(),
                ImportLookupSheets::programmes(null),
                ImportLookupSheets::levels(),
            ],
            default => [],
        };
    }

    private function assertType(string $type): string
    {
        $type = strtolower(trim($type));
        if (! in_array($type, CatalogImportColumns::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown catalogue import type.');
        }

        return $type;
    }
}
