<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use App\Support\GradeAuditLogger;
use App\Support\GradeLetterResolver;
use App\Support\GradeScoreComposer;
use App\Support\GradeStatus;
use App\Support\ResultImportColumns;
use App\Support\ResultOfficerScope;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradeEntryService
{
    public function __construct(private GradeWorkflowService $workflow) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(array $data, User $actor): Grade
    {
        $sitting = $data['sitting'] ?? GradeStatus::SITTING_MAIN;
        [$studentId, $offeringId, $enrollment, $course] = $this->resolveTarget($data);

        $org = GradeWorkflowService::orgSnapshotFromCourse($course);
        ResultOfficerScope::assertLaneAccess($actor, $org);

        $grade = Grade::query()
            ->where('student_id', $studentId)
            ->where('course_offering_id', $offeringId)
            ->where('sitting', $sitting)
            ->first();

        if ($grade && ! $grade->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or correction-required grades can be edited.',
            ]);
        }

        $letter = isset($data['letter']) && $data['letter'] !== ''
            ? strtoupper(trim((string) $data['letter']))
            : null;
        $points = array_key_exists('points', $data) && $data['points'] !== null && $data['points'] !== ''
            ? (float) $data['points']
            : null;
        $composed = GradeScoreComposer::compose(
            GradeScoreComposer::parseNullableFloat($data['ca_score'] ?? null),
            GradeScoreComposer::parseNullableFloat($data['exam_score'] ?? null),
            GradeScoreComposer::parseNullableFloat($data['score'] ?? null),
            array_key_exists('ca_score', $data),
            array_key_exists('exam_score', $data),
            array_key_exists('score', $data),
            $grade,
        );

        if ($letter && $points === null) {
            $points = GradeLetterResolver::gradePointForLetter($letter);
        } elseif (! $letter && $composed['score'] !== null) {
            $resolved = GradeLetterResolver::fromScore((float) $composed['score']);
            $letter = $resolved['letter'] ?? null;
            $points = $resolved['grade_point'] ?? null;
        }

        $before = $grade?->only(['ca_score', 'exam_score', 'score', 'letter', 'points', 'status']);

        $payload = [
            'student_id' => $studentId,
            'course_offering_id' => $offeringId,
            'enrollment_id' => $enrollment?->id,
            'sitting' => $sitting,
            'ca_score' => $composed['ca_score'],
            'exam_score' => $composed['exam_score'],
            'score' => $composed['score'],
            'letter' => $letter,
            'points' => $points,
            'source' => $data['source'] ?? ($grade?->source ?: 'manual'),
            'source_ref' => $data['source_ref'] ?? $grade?->source_ref,
            'upload_lane' => $org['upload_lane'],
            'faculty_id' => $org['faculty_id'],
            'department_id' => $org['department_id'],
            'entered_by' => $actor->id,
            'status' => $grade?->status ?: GradeStatus::DRAFT,
        ];

        return DB::transaction(function () use ($grade, $payload, $actor, $before) {
            if ($grade) {
                $grade->fill($payload)->save();
                GradeAuditLogger::updated($grade, $actor, $before ?? [], $grade->only(['ca_score', 'exam_score', 'score', 'letter', 'points', 'status']));

                return $grade->fresh()->loadMissing(['student', 'offering.course', 'offering.term', 'enrollment.offering.course', 'enrollment.student']);
            }

            $created = Grade::query()->create($payload);
            GradeAuditLogger::created($created, $actor);

            return $created->fresh()->loadMissing(['student', 'offering.course', 'offering.term', 'enrollment.offering.course', 'enrollment.student']);
        });
    }

    public function destroy(Grade $grade, User $actor): void
    {
        if (! $grade->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or correction-required grades can be deleted.',
            ]);
        }

        ResultOfficerScope::assertCanMutate($actor, $grade);
        GradeAuditLogger::deleted($grade, $actor);
        $grade->delete();
    }

    public function importTemplate(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            ResultImportColumns::SHEET,
            ResultImportColumns::all(),
            ResultImportColumns::instructions(),
            ResultImportColumns::samples(),
            ResultImportColumns::FILENAME,
        );
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function importUpload(UploadedFile $file, int $courseOfferingId, string $scoreComponent, User $actor, string $sitting = GradeStatus::SITTING_MAIN): array
    {
        return $this->importCsv($this->fileToCsv($file), $courseOfferingId, $scoreComponent, $actor, $sitting);
    }

    public function fileToCsv(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->spreadsheetToCsv($file->getRealPath() ?: '');
        }

        return (string) file_get_contents($file->getRealPath());
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function importCsv(string $csv, int $courseOfferingId, string $scoreComponent, User $actor, string $sitting = GradeStatus::SITTING_MAIN): array
    {
        $offering = CourseOffering::query()->with('course.department')->findOrFail($courseOfferingId);
        ResultOfficerScope::assertOfferingAccess($actor, $offering);

        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'CSV is empty.']);
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = $cols[$i] ?? null;
            }

            $matric = trim((string) ($row['matric'] ?? $row['matric_number'] ?? ''));
            if ($matric === '') {
                $errors[] = 'Row '.($index + 2).': missing matric.';

                continue;
            }

            $student = Student::query()->where('matric_number', $matric)->first();
            if (! $student) {
                $errors[] = 'Row '.($index + 2).": student {$matric} not found.";

                continue;
            }

            $data = [
                'student_id' => $student->id,
                'course_offering_id' => $courseOfferingId,
                'sitting' => $sitting,
                'source' => 'import',
            ];

            if (isset($row['ca']) || isset($row['exam'])) {
                if (isset($row['ca'])) {
                    $data['ca_score'] = $row['ca'];
                }
                if (isset($row['exam'])) {
                    $data['exam_score'] = $row['exam'];
                }
            } else {
                $score = $row['score'] ?? $row['total'] ?? null;
                match ($scoreComponent) {
                    'ca' => $data['ca_score'] = $score,
                    'exam' => $data['exam_score'] = $score,
                    default => $data['score'] = $score,
                };
            }

            try {
                $existing = Grade::query()
                    ->where('student_id', $student->id)
                    ->where('course_offering_id', $courseOfferingId)
                    ->where('sitting', $data['sitting'])
                    ->exists();
                $this->upsert($data, $actor);
                $existing ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($index + 2).': '.$e->getMessage();
            }
        }

        return compact('created', 'updated', 'errors');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int, 2: ?Enrollment, 3: Course}
     */
    private function resolveTarget(array $data): array
    {
        if (! empty($data['enrollment_id'])) {
            $enrollment = Enrollment::query()
                ->with(['offering.course.department', 'student'])
                ->findOrFail($data['enrollment_id']);

            return [
                (int) $enrollment->student_id,
                (int) $enrollment->course_offering_id,
                $enrollment->status === 'enrolled' ? $enrollment : null,
                $enrollment->offering->course,
            ];
        }

        $studentId = (int) ($data['student_id'] ?? 0);
        $offeringId = (int) ($data['course_offering_id'] ?? 0);
        if ($studentId <= 0 || $offeringId <= 0) {
            throw ValidationException::withMessages([
                'course_offering_id' => 'Provide a course offering and student, or an enrollment.',
            ]);
        }

        Student::query()->findOrFail($studentId);
        $offering = CourseOffering::query()->with('course.department')->findOrFail($offeringId);
        $enrollment = Enrollment::query()
            ->where('student_id', $studentId)
            ->where('course_offering_id', $offeringId)
            ->enrolled()
            ->first();

        return [$studentId, $offeringId, $enrollment, $offering->course];
    }

    private function spreadsheetToCsv(string $path): string
    {
        if ($path === '' || ! is_readable($path)) {
            throw ValidationException::withMessages(['file' => 'Unable to read the uploaded spreadsheet.']);
        }

        $rows = SpreadsheetImport::readRows($path, ResultImportColumns::SHEET);
        if ($rows === [] || SpreadsheetImport::rowEmpty($rows[0] ?? [])) {
            $rows = SpreadsheetImport::readRows($path);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Spreadsheet is empty.']);
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Unable to prepare the spreadsheet for import.']);
        }
        foreach ($rows as $row) {
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}
