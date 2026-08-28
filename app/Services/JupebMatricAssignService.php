<?php

namespace App\Services;

use App\Models\Student;
use App\Support\JupebMatricColumns;
use App\Support\NinCipher;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JupebMatricAssignService
{
    public function __construct(
        private AuditWriter $audit,
        private Notifier $notifier,
    ) {}

    public function template(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            'Matric',
            JupebMatricColumns::all(),
            JupebMatricColumns::instructions(),
            JupebMatricColumns::sample(),
            'jupeb-matric-template.xlsx',
            [$this->pendingLookupSheet()],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->jupebStudents()
            ->where(function ($query) {
                $query->whereNull('matric_number')->orWhere('matric_number', '');
            })
            ->with(['user:id,email', 'application:id,application_number,user_id', 'program:id,name,code'])
            ->orderBy('id')
            ->get()
            ->map(fn (Student $student) => $this->serialize($student))
            ->all();
    }

    /**
     * @return array{student: array<string, mixed>, created: bool}
     */
    public function assign(string $matricNumber, array $identifiers): array
    {
        $matric = $this->normalizeMatric($matricNumber);
        if ($matric === '') {
            throw new RuntimeException('matric_number is required.');
        }

        $student = $this->findStudent($identifiers);
        if (! $student) {
            throw new RuntimeException('No matching JUPEB student was found.');
        }

        $this->assertMatricAvailable($matric, $student);
        $existing = trim((string) $student->matric_number);
        if ($existing !== '' && strtoupper(str_replace(' ', '', $existing)) === $matric) {
            return ['student' => $this->serialize($student), 'created' => false];
        }
        if ($existing !== '') {
            throw new RuntimeException('This student already has a matric number.');
        }

        $before = $student->matric_number;
        $student->update(['matric_number' => $matric]);
        $this->audit->record(
            'student.jupeb_matric',
            'JUPEB matric number assigned',
            'admissions',
            'student',
            $student->id,
            ['matric_number' => $before],
            ['matric_number' => $matric],
        );
        if ($student->user) {
            $this->notifier->send(
                $student->user,
                'student_created',
                'JUPEB matric number issued',
                'Your JUPEB matric number is '.$matric.'. Use it to sign in.',
                'sis',
                $student->id,
            );
        }

        return ['student' => $this->serialize($student->fresh(['user', 'application', 'program'])), 'created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $rows = SpreadsheetImport::readRows($path, 'Matric');
        if ($rows === []) {
            $rows = SpreadsheetImport::readRows($path);
        }
        $headers = $rows[0] ?? [];
        $indexes = SpreadsheetImport::indexHeaders(is_array($headers) ? $headers : []);
        if (! isset($indexes['matric_number'])) {
            throw new \InvalidArgumentException('The spreadsheet must include a matric_number column.');
        }

        $assigned = 0;
        $skipped = 0;
        $errors = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i] ?? [];
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            $data = SpreadsheetImport::mapRow($row, $indexes);
            try {
                $result = $this->assign((string) ($data['matric_number'] ?? ''), $data);
                if ($result['created']) {
                    $assigned++;
                } else {
                    $skipped++;
                }
            } catch (RuntimeException $e) {
                $skipped++;
                $errors[] = [
                    'row' => $i + 1,
                    'application_number' => $data['application_number'] ?? '',
                    'student_number' => $data['student_number'] ?? '',
                    'email' => $data['email'] ?? '',
                    'matric_number' => $data['matric_number'] ?? '',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $identifiers
     */
    private function findStudent(array $identifiers): ?Student
    {
        $query = $this->jupebStudents()->with(['user', 'application', 'program']);
        $applicationNumber = strtoupper(trim((string) ($identifiers['application_number'] ?? '')));
        $studentNumber = strtoupper(str_replace(' ', '', (string) ($identifiers['student_number'] ?? '')));
        $email = strtolower(trim((string) ($identifiers['email'] ?? '')));
        $nin = NinCipher::normalize((string) ($identifiers['nin'] ?? ''));
        $studentId = (int) ($identifiers['student_id'] ?? 0);

        if ($studentId > 0) {
            return $query->whereKey($studentId)->first();
        }

        if ($applicationNumber === '' && $studentNumber === '' && $email === '' && $nin === '') {
            throw new RuntimeException('Provide application_number, student_number, email, or nin.');
        }

        $query->where(function ($builder) use ($applicationNumber, $studentNumber, $email, $nin) {
            if ($applicationNumber !== '') {
                $builder->orWhereHas('application', function ($applications) use ($applicationNumber) {
                    $applications->whereRaw('UPPER(REPLACE(application_number, " ", "")) = ?', [str_replace(' ', '', $applicationNumber)]);
                });
            }
            if ($studentNumber !== '') {
                $builder->orWhereRaw('UPPER(REPLACE(COALESCE(student_number, ""), " ", "")) = ?', [$studentNumber]);
            }
            if ($email !== '') {
                $builder->orWhereHas('user', fn ($users) => $users->whereRaw('LOWER(email) = ?', [$email]));
            }
            if ($nin !== '') {
                $builder->orWhere('nin_hash', NinCipher::hash($nin));
            }
        });

        $matches = $query->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('More than one JUPEB student matched these identifiers.');
        }

        return $matches->first();
    }

    private function assertMatricAvailable(string $matric, Student $student): void
    {
        $taken = Student::query()
            ->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$matric])
            ->where('id', '!=', $student->id)
            ->exists();
        if ($taken) {
            throw new RuntimeException('This matric number is already assigned to another student.');
        }
    }

    private function normalizeMatric(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?: '');
    }

    private function jupebStudents()
    {
        return Student::query()->where(function ($query) {
            $query->where('study_level', 'jupeb')
                ->orWhereHas('application', fn ($applications) => $applications->where('entry_mode', 'jupeb'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Student $student): array
    {
        return [
            'id' => $student->id,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'student_number' => $student->student_number,
            'matric_number' => $student->matric_number,
            'email' => $student->user?->email,
            'application_number' => $student->application?->application_number,
            'programme' => $student->program?->name,
        ];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function pendingLookupSheet(): array
    {
        $rows = [];
        foreach ($this->pending() as $student) {
            $rows[] = [
                $student['application_number'] ?? '',
                $student['student_number'] ?? '',
                $student['email'] ?? '',
                trim(($student['last_name'] ?? '').' '.($student['first_name'] ?? '')),
                $student['programme'] ?? '',
            ];
        }

        return [
            'title' => 'Pending students',
            'headers' => ['application_number', 'student_number', 'email', 'name', 'programme'],
            'rows' => $rows,
        ];
    }
}
