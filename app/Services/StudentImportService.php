<?php

namespace App\Services;

use App\Mail\ApplicationCredentialsMail;
use App\Models\Application;
use App\Models\ApplicationStep;
use App\Models\Intake;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\ApplicantImportColumns;
use App\Support\ApplicationReference;
use App\Support\ImportLookupSheets;
use App\Support\NinCipher;
use App\Support\SpreadsheetImport;
use App\Support\StudentImportColumns;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StudentImportService
{
    public function __construct(
        private PremblyService $prembly,
        private StudentCreationService $students,
        private InvoiceImportService $invoices,
        private WalletImportService $wallets,
        private AuditWriter $audit,
    ) {}

    public function template(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            'Students',
            StudentImportColumns::all(),
            [
                'Import students — continuing / returning students',
                '',
                '1. Import invoices and wallet history first (keyed by matric_number). Those rows stay pending until this step creates the student.',
                '2. Keep the header row on the Students sheet. One row per student. Do not paste data into Instructions or lookup sheets.',
                '3. Copy programme_id from the Programmes sheet (id column). It must match a programme that accepts this entry mode.',
                '4. Copy current_level from the Levels sheet (code column). Use 100, 200, 300, 400, or 500 (or 1–5, stored as 100–500).',
                '5. Lookup sheets (Campuses, Colleges, Departments, Programmes, Levels) are for reference. Import reads only the Students sheet.',
                '6. Leave password blank to generate a new password. Tick Email portal passwords on upload to send it.',
                '7. Duplicate email, NIN, JAMB, matric number, or old application number is skipped.',
                '',
                'Required columns: '.implode(', ', StudentImportColumns::required()),
            ],
            StudentImportColumns::sample(),
            'student-import-template.xlsx',
            ImportLookupSheets::forStudents(),
        );
    }

    /**
     * @param  array{verify_nin?: bool, send_credentials?: bool}  $options
     * @return array<string, mixed>
     */
    public function import(UploadedFile|string $file, Intake $intake, string $entryMode, array $options = []): array
    {
        $entryMode = $this->normalizeMode($entryMode);
        if ($intake->entry_mode !== $entryMode) {
            throw new \InvalidArgumentException('The selected application window does not match this student category.');
        }

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $rows = SpreadsheetImport::readRows($path, 'Students');
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $headers = array_map(fn ($value) => SpreadsheetImport::normalizeHeader((string) $value), $rows[0]);
        $indexes = SpreadsheetImport::indexHeaders($headers);
        $verifyNin = (bool) ($options['verify_nin'] ?? false);
        $sendCredentials = array_key_exists('send_credentials', $options)
            ? (bool) $options['send_credentials']
            : true;

        $created = 0;
        $skipped = 0;
        $emailed = 0;
        $ninFailed = 0;
        $invoicesPosted = 0;
        $walletPosted = 0;
        $errors = [];
        $role = $this->applicantRole();

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $line = $i + 1;
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            $data = SpreadsheetImport::mapRow($row, $indexes);
            try {
                $result = DB::transaction(function () use ($data, $intake, $entryMode, $verifyNin, $sendCredentials, $role) {
                    return $this->importRow($data, $intake, $entryMode, $verifyNin, $sendCredentials, $role);
                });
                $created++;
                if ($result['emailed']) {
                    $emailed++;
                }
                $invoicesPosted += $result['invoices_posted'];
                $walletPosted += $result['wallet_posted'];
            } catch (Throwable $e) {
                $skipped++;
                if ($verifyNin && str_contains(strtolower($e->getMessage()), 'nin')) {
                    $ninFailed++;
                }
                $errors[] = [
                    'row' => $line,
                    'email' => $data['email'] ?? '',
                    'nin' => $data['nin'] ?? '',
                    'matric_number' => $data['matric_number'] ?? '',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $summary = [
            'created' => $created,
            'skipped' => $skipped,
            'emailed' => $emailed,
            'nin_failed' => $ninFailed,
            'invoices_posted' => $invoicesPosted,
            'wallet_posted' => $walletPosted,
            'errors' => $errors,
            'entry_mode' => $entryMode,
            'intake_id' => $intake->id,
        ];
        $this->audit->record(
            'students.imported',
            "Imported {$created} student(s) for {$entryMode}",
            'admissions',
        );

        return $summary;
    }

    public function countDataRows(UploadedFile|string $file): int
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path) {
            return 0;
        }

        return SpreadsheetImport::countDataRows($path, 'Students');
    }

    public function shouldQueue(bool $verifyNin, int $rowCount): bool
    {
        return SpreadsheetImport::shouldQueue($rowCount, $verifyNin);
    }

    public function storeUpload(UploadedFile $file): string
    {
        return SpreadsheetImport::storeUpload($file, 'student-imports');
    }

    public function cacheResult(string $importId, array $result): void
    {
        SpreadsheetImport::cacheResult($this->cacheKey($importId), $result);
    }

    public function cachedResult(string $importId): ?array
    {
        return SpreadsheetImport::cachedResult($this->cacheKey($importId));
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function errorSpreadsheet(array $errors): StreamedResponse
    {
        return SpreadsheetImport::errorSpreadsheet(
            $errors,
            ['row', 'email', 'nin', 'matric_number', 'message'],
            'student-import-errors.xlsx',
        );
    }

    /**
     * @param  array<string, string>  $data
     * @return array{emailed: bool, invoices_posted: int, wallet_posted: int}
     */
    private function importRow(
        array $data,
        Intake $intake,
        string $entryMode,
        bool $verifyNin,
        bool $sendCredentials,
        Role $role,
    ): array {
        foreach (StudentImportColumns::required() as $field) {
            if (blank($data[$field] ?? null)) {
                throw new RuntimeException("Missing required field: {$field}.");
            }
        }

        $email = strtolower(trim($data['email']));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }

        $nin = NinCipher::normalize($data['nin']);
        if (strlen($nin) !== 11) {
            throw new RuntimeException('NIN must be 11 digits.');
        }

        $jamb = strtoupper(str_replace(' ', '', (string) ($data['jamb_registration'] ?? '')));
        $oldNumber = strtoupper(trim((string) ($data['old_application_number'] ?? '')));
        $matric = strtoupper(str_replace(' ', '', (string) $data['matric_number']));
        if ($matric === '') {
            throw new RuntimeException('matric_number is required.');
        }

        $this->assertNotDuplicate($email, $nin, $jamb, $oldNumber, $matric);

        $program = $this->resolveProgram($data['programme_id'], $entryMode);
        $level = $this->normalizeLevel($data['current_level'], $entryMode);

        $plainPassword = trim((string) ($data['password'] ?? ''));
        if ($plainPassword === '') {
            $plainPassword = $this->generatePassword();
        }

        $name = trim(implode(' ', array_filter([
            $data['first_name'],
            $data['middle_name'] ?? '',
            $data['last_name'],
        ])));

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => trim($data['phone']),
            'jamb_registration' => $jamb !== '' ? $jamb : null,
            'password' => $plainPassword,
            'status' => 'active',
            'portal_credential_cipher' => $sendCredentials
                ? Crypt::encryptString($plainPassword)
                : null,
        ]);
        $user->roles()->sync([$role->id]);

        $applicationNumber = $oldNumber !== '' ? $oldNumber : ApplicationReference::generate();
        $application = Application::query()->create([
            'application_number' => $applicationNumber,
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'program_id' => $program->id,
            'entry_mode' => $entryMode,
            'jamb_registration' => $jamb !== '' ? $jamb : null,
            'jamb_status' => $jamb !== '' ? 'pending' : null,
            'stage' => 'matriculated',
            'current_step' => 'required_documents',
            'submitted_at' => now(),
        ]);

        foreach (Application::formSteps($entryMode) as $key) {
            $application->steps()->create([
                'step_key' => $key,
                'status' => 'saved',
                'payload' => [],
            ]);
        }

        $this->writeSteps($application, $data, $program, $nin, $verifyNin);

        if ($verifyNin) {
            try {
                $this->prembly->verify($user, $application, $nin);
            } catch (ValidationException $e) {
                throw new RuntimeException(collect($e->errors())->flatten()->first() ?: 'NIN verification failed.');
            }
        }

        $studentNumber = trim((string) ($data['student_number'] ?? '')) ?: null;
        $student = $this->students->createFromImport($application->fresh(), $matric, $level, $studentNumber);

        $invoiceResult = $this->invoices->postPendingForMatric($student);
        $walletResult = $this->wallets->postPendingForMatric($student);

        $emailed = false;
        if ($sendCredentials) {
            Mail::to($user->email)->send(new ApplicationCredentialsMail(
                $application->fresh(['user']),
                $matric,
                $plainPassword,
            ));
            $application->update(['credentials_emailed_at' => now()]);
            $user->update(['portal_credential_cipher' => null]);
            $emailed = true;
        }

        return [
            'emailed' => $emailed,
            'invoices_posted' => $invoiceResult['posted'],
            'wallet_posted' => $walletResult['posted'],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function writeSteps(Application $application, array $data, Program $program, string $nin, bool $verifyNin): void
    {
        $application->load('steps');
        $this->saveStep($application, 'biodata', [
            'nin' => $nin,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? '',
            'last_name' => $data['last_name'],
            'date_of_birth' => SpreadsheetImport::parseDate($data['date_of_birth'] ?? null),
            'gender' => $data['gender'] ?? '',
            'nin_locked' => $verifyNin,
        ]);
        $this->saveStep($application, 'personal_details', [
            'marital_status' => $data['marital_status'] ?? '',
            'religion' => $data['religion'] ?? '',
            'country' => $data['country'] ?? '',
            'state' => $data['state'] ?? '',
            'lga' => $data['lga'] ?? '',
        ]);
        $this->saveStep($application, 'health_information', [
            'blood_group' => $data['blood_group'] ?? '',
            'genotype' => $data['genotype'] ?? '',
            'has_medical_condition' => $this->boolish($data['has_medical_condition'] ?? null),
            'medical_condition_details' => $data['medical_condition_details'] ?? '',
        ]);
        $this->saveStep($application, 'next_of_kin', [
            'next_of_kin' => $data['next_of_kin_name'] ?? '',
            'next_of_kin_relationship' => $data['next_of_kin_relationship'] ?? '',
            'next_of_kin_phone' => $data['next_of_kin_phone'] ?? '',
            'next_of_kin_email' => $data['next_of_kin_email'] ?? '',
            'next_of_kin_address' => $data['next_of_kin_address'] ?? '',
        ]);
        $this->saveStep($application, 'sponsor', [
            'sponsor_name' => $data['sponsor_name'] ?? '',
            'sponsor_relationship' => $data['sponsor_relationship'] ?? '',
            'sponsor_phone' => $data['sponsor_phone'] ?? '',
            'sponsor_email' => $data['sponsor_email'] ?? '',
            'sponsor_address' => $data['sponsor_address'] ?? '',
        ]);
        $this->saveStep($application, 'application_form', [
            'phone' => $data['phone'] ?? '',
            'address' => $data['address'] ?? '',
        ]);
        $this->saveStep($application, 'programme_selection', [
            'first_choice_programme_id' => $program->id,
            'first_choice_programme_code' => $program->code,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveStep(Application $application, string $key, array $payload): void
    {
        $step = $application->steps->firstWhere('step_key', $key);
        if (! $step) {
            return;
        }
        $step->payload = $payload;
        $step->status = 'saved';
        $step->save();
    }

    private function resolveProgram(string $value, string $entryMode): Program
    {
        $id = SpreadsheetImport::parseId($value, 'programme_id');
        $program = Program::query()->find($id);
        if (! $program) {
            throw new RuntimeException("Unknown programme id: {$id}.");
        }
        if (! $program->is_active) {
            throw new RuntimeException("Programme {$id} is not active.");
        }
        $modes = $program->entry_modes ?? [];
        if ($modes !== [] && ! in_array($entryMode, $modes, true)) {
            throw new RuntimeException("Programme {$id} does not accept {$entryMode} students.");
        }

        return $program;
    }

    private function assertNotDuplicate(string $email, string $nin, string $jamb, string $oldNumber, string $matric): void
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new RuntimeException('An account with this email already exists.');
        }
        $hash = NinCipher::hash($nin);
        if (\App\Models\NinVerification::query()->where('nin_hash', $hash)->exists()) {
            throw new RuntimeException('This NIN is already linked to an account.');
        }
        foreach (ApplicationStep::query()->where('step_key', 'biodata')->cursor() as $step) {
            $payload = is_array($step->payload) ? $step->payload : [];
            if (NinCipher::normalize((string) ($payload['nin'] ?? '')) === $nin) {
                throw new RuntimeException('This NIN is already linked to an account.');
            }
        }
        if ($jamb !== '' && User::query()->where('jamb_registration', $jamb)->exists()) {
            throw new RuntimeException('This JAMB registration is already linked to an account.');
        }
        if ($oldNumber !== '' && Application::query()->where('application_number', $oldNumber)->exists()) {
            throw new RuntimeException('This application number already exists.');
        }
        if (Student::query()->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$matric])->exists()) {
            throw new RuntimeException('This matric number already exists.');
        }
    }

    private function normalizeLevel(string $value, string $entryMode): int
    {
        $n = (int) $value;
        if ($n <= 0) {
            throw new RuntimeException('current_level is required.');
        }
        $level = $n < 100 ? $n * 100 : $n;
        if ($entryMode === 'pg') {
            return $level >= 100 ? 1 : $level;
        }
        if (! in_array($level, [100, 200, 300, 400, 500], true)) {
            throw new RuntimeException('current_level must be 100, 200, 300, 400, or 500.');
        }

        return $level;
    }

    private function applicantRole(): Role
    {
        return Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
    }

    private function generatePassword(): string
    {
        return 'Aa1!'.Str::password(10, symbols: true);
    }

    private function normalizeMode(string $entryMode): string
    {
        $entryMode = strtolower(trim($entryMode));
        if (! in_array($entryMode, ApplicantImportColumns::MODES, true)) {
            throw new \InvalidArgumentException('Unknown student category.');
        }

        return $entryMode;
    }

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y'], true);
    }

    private function cacheKey(string $importId): string
    {
        return 'student-import:'.$importId;
    }
}
