<?php

namespace App\Services;

use App\Mail\ApplicationCredentialsMail;
use App\Models\Application;
use App\Models\ApplicationStep;
use App\Models\Intake;
use App\Models\Lga;
use App\Models\OlevelSubject;
use App\Models\Program;
use App\Models\Role;
use App\Models\StateOfOrigin;
use App\Models\User;
use App\Support\AdmissionEntryRules;
use App\Support\ApplicantImportColumns;
use App\Support\ApplicationReference;
use App\Support\ImportLookupSheets;
use App\Support\NinCipher;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ApplicantImportService
{
    public const QUEUE_ROW_THRESHOLD = 40;

    public function __construct(
        private PremblyService $prembly,
        private InvoiceImportService $invoices,
        private InvoiceService $invoiceService,
        private AuditWriter $audit,
    ) {}

    public function template(string $entryMode): StreamedResponse
    {
        $entryMode = $this->normalizeMode($entryMode);

        return SpreadsheetImport::templateDownload(
            'Applicants',
            ApplicantImportColumns::forMode($entryMode),
            [
                'Import applicants — '.$entryMode,
                '',
                '1. Keep the header row on the Applicants sheet. Do not rename columns. Do not paste data into Instructions or lookup sheets.',
                '2. Fill one row per applicant. Leave password blank to generate a new password and email it.',
                '3. Copy ids from this workbook’s lookup sheets. Do not paste names from the old portal — spellings will not match. first_choice_programme_id / second_choice_programme_id from Programmes. state_id from States. lga_id from LGAs (must belong to that state). sitting*_subject_*_id from O-level subjects. Import stores this system’s official titles.',
                '4. Country is Nigeria or Non-Nigeria (text). Do not enter college or department on the Applicants sheet — those lookup sheets are reference only. College and department come from the programme you pick.',
                '5. O-level: maximum 2 sittings (sitting1_ and sitting2_ columns). Sitting 2 is optional.',
                '6. UTME/JUPEB: exactly 2 JAMB institution choices (utme_institution_1 / utme_programme_1 and utme_institution_2 / utme_programme_2). Enter institution and programme names, not ids.',
                '7. Lookup sheets (Campuses, Colleges, Departments, Programmes, Levels, States, LGAs, O-level subjects) are for reference. Import reads only the Applicants sheet.',
                '8. Documents are not imported from Excel. Import does not submit the application. Applicants must upload required documents and submit after they sign in.',
                '9. If Verify NIN is checked on upload, Prembly is called for every NIN.',
                '10. Login on the student portal uses application number (APP/YYYY/#####) or JAMB registration, not email.',
                '11. Import invoices first when application fee was paid on the old portal (category application_fee, keyed by application_number or JAMB). Matching rows are posted and marked paid.',
                '12. If no matching invoice is found, an unpaid application fee is generated when a catalog or session amount exists. Import still succeeds if the fee cannot be generated — the student portal will ask the applicant to pay.',
                '',
                'Required columns: '.implode(', ', ApplicantImportColumns::required($entryMode)),
            ],
            ApplicantImportColumns::sample($entryMode),
            "applicant-import-{$entryMode}-template.xlsx",
            ImportLookupSheets::forApplicants($entryMode),
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
            throw new \InvalidArgumentException('The selected application window does not match this applicant category.');
        }

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $rows = $this->readRows($path);
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), $rows[0]);
        $indexes = $this->indexHeaders($headers);
        $verifyNin = (bool) ($options['verify_nin'] ?? false);
        $sendCredentials = array_key_exists('send_credentials', $options)
            ? (bool) $options['send_credentials']
            : true;

        $created = 0;
        $skipped = 0;
        $emailed = 0;
        $ninFailed = 0;
        $invoicesPosted = 0;
        $applicationFeesGenerated = 0;
        $errors = [];
        $role = $this->applicantRole();

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $line = $i + 1;
            if (! is_array($row) || $this->rowEmpty($row)) {
                continue;
            }
            $data = $this->mapRow($row, $indexes);
            try {
                $result = DB::transaction(function () use ($data, $intake, $entryMode, $verifyNin, $sendCredentials, $role) {
                    return $this->importRow($data, $intake, $entryMode, $verifyNin, $sendCredentials, $role);
                });
                $created++;
                if ($result['emailed']) {
                    $emailed++;
                }
                $invoicesPosted += $result['invoices_posted'];
                if (! empty($result['application_fee_generated'])) {
                    $applicationFeesGenerated++;
                }
            } catch (Throwable $e) {
                $skipped++;
                if ($verifyNin && str_contains(strtolower($e->getMessage()), 'nin')) {
                    $ninFailed++;
                }
                $errors[] = [
                    'row' => $line,
                    'email' => $data['email'] ?? '',
                    'nin' => $data['nin'] ?? '',
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
            'application_fees_generated' => $applicationFeesGenerated,
            'errors' => $errors,
            'entry_mode' => $entryMode,
            'intake_id' => $intake->id,
        ];

        $this->audit->record(
            'applicants.imported',
            "Imported {$created} applicant(s) for {$entryMode}",
            'admissions',
            'application',
            null,
            null,
            [
                'created' => $created,
                'skipped' => $skipped,
                'emailed' => $emailed,
                'nin_failed' => $ninFailed,
                'verify_nin' => $verifyNin,
            ],
        );

        return $summary;
    }

    public function countDataRows(UploadedFile|string $file): int
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path) {
            return 0;
        }
        $rows = $this->readRows($path);
        $count = 0;
        for ($i = 1; $i < count($rows); $i++) {
            if (is_array($rows[$i]) && ! $this->rowEmpty($rows[$i])) {
                $count++;
            }
        }

        return $count;
    }

    public function shouldQueue(bool $verifyNin, int $rowCount): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        return $verifyNin || $rowCount >= self::QUEUE_ROW_THRESHOLD;
    }

    public function storeUpload(UploadedFile $file): string
    {
        return $file->store('applicant-imports');
    }

    public function cacheResult(string $importId, array $result): void
    {
        Cache::put($this->cacheKey($importId), $result, now()->addHours(6));
    }

    public function cachedResult(string $importId): ?array
    {
        $result = Cache::get($this->cacheKey($importId));

        return is_array($result) ? $result : null;
    }

    public function errorSpreadsheet(array $errors): StreamedResponse
    {
        return SpreadsheetImport::errorSpreadsheet(
            $errors,
            ['row', 'email', 'nin', 'message'],
            'applicant-import-errors.xlsx',
        );
    }

    private function cacheKey(string $importId): string
    {
        return 'applicant-import:'.$importId;
    }

    /**
     * @param  array<string, string>  $data
     * @return array{emailed: bool, invoices_posted: int, application_fee_generated: bool}
     */
    private function importRow(
        array $data,
        Intake $intake,
        string $entryMode,
        bool $verifyNin,
        bool $sendCredentials,
        Role $role,
    ): array {
        foreach (ApplicantImportColumns::required($entryMode) as $field) {
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

        $this->assertNotDuplicate($email, $nin, $jamb, $oldNumber, $intake->id);

        $firstProgram = $this->resolveProgram($data['first_choice_programme_id'], $entryMode);
        $secondProgram = filled($data['second_choice_programme_id'] ?? null)
            ? $this->resolveProgram($data['second_choice_programme_id'], $entryMode)
            : null;

        $plainPassword = trim((string) ($data['password'] ?? ''));
        $generated = $plainPassword === '';
        if ($generated) {
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
        $complete = $this->rowLooksComplete($data, $entryMode);

        $application = Application::query()->create([
            'application_number' => $applicationNumber,
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'program_id' => $firstProgram->id,
            'entry_mode' => $entryMode,
            'jamb_registration' => $jamb !== '' ? $jamb : null,
            'jamb_status' => $jamb !== '' ? 'pending' : null,
            'stage' => 'form_in_progress',
            'current_step' => $complete ? 'required_documents' : 'biodata',
            'submitted_at' => null,
        ]);

        foreach (Application::formSteps($entryMode) as $key) {
            $application->steps()->create([
                'step_key' => $key,
                'status' => $key === 'required_documents'
                    ? 'pending'
                    : ($complete || $key === 'biodata' ? 'saved' : 'pending'),
                'payload' => [],
            ]);
        }

        $this->writeSteps($application, $data, $firstProgram, $secondProgram, $nin, $verifyNin);

        if ($verifyNin) {
            try {
                $this->prembly->verify($user, $application, $nin);
            } catch (ValidationException $e) {
                throw new RuntimeException(collect($e->errors())->flatten()->first() ?: 'NIN verification failed.');
            } catch (RuntimeException $e) {
                throw $e;
            }
        }

        $invoiceResult = $this->invoices->postPendingForApplication($application->fresh(['user', 'student']));

        $application = $application->fresh(['user', 'intake']);
        $applicationFeeGenerated = false;
        if (! $application->application_fee_invoice_id) {
            try {
                $invoice = $this->invoiceService->createApplicationFeeInvoice(
                    $application->user,
                    $intake,
                    $application->id,
                );
                $application->update(['application_fee_invoice_id' => $invoice->id]);
                $applicationFeeGenerated = true;
            } catch (RuntimeException $e) {
                Log::info('applicant_import.application_fee_skipped', [
                    'application_id' => $application->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $emailed = false;
        if ($sendCredentials) {
            Mail::to($user->email)->send(new ApplicationCredentialsMail(
                $application->fresh(['user']),
                AdmissionEntryRules::requiresJambRegistration($entryMode) && $jamb !== ''
                    ? $jamb
                    : $applicationNumber,
                $plainPassword,
            ));
            $application->update(['credentials_emailed_at' => now()]);
            $user->update(['portal_credential_cipher' => null]);
            $emailed = true;
        }

        return [
            'emailed' => $emailed,
            'invoices_posted' => $invoiceResult['posted'],
            'application_fee_generated' => $applicationFeeGenerated,
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function writeSteps(
        Application $application,
        array $data,
        Program $first,
        ?Program $second,
        string $nin,
        bool $verifyNin,
    ): void {
        $application->load('steps');

        $this->saveStep($application, 'biodata', [
            'nin' => $nin,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? '',
            'last_name' => $data['last_name'],
            'date_of_birth' => $this->date($data['date_of_birth'] ?? null),
            'gender' => $data['gender'] ?? '',
            'nin_locked' => $verifyNin,
        ]);
        [$state, $lga] = $this->resolveStateAndLga($data);
        $this->saveStep($application, 'personal_details', [
            'marital_status' => $data['marital_status'] ?? '',
            'religion' => $data['religion'] ?? '',
            'country' => $this->country($data['country'] ?? null),
            'state' => $state?->state_title ?? '',
            'state_id' => $state?->state_id,
            'lga' => $lga?->lga_title ?? '',
            'lga_id' => $lga?->lga_id,
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
            'phone' => $data['phone'],
            'address' => $data['address'] ?? '',
            'declaration' => true,
        ]);
        $this->saveStep($application, 'academic_qualifications', $this->academicPayload($data));
        if ($application->entry_mode === 'utme') {
            $utme = $this->utmePayload($data);
            if ($utme !== null) {
                $this->saveStep($application, 'utme', ['utme' => $utme]);
            }
        }
        $this->saveStep($application, 'programme_selection', [
            'first_choice_program_id' => $first->id,
            'second_choice_program_id' => $second?->id,
            'program_id' => $first->id,
        ]);

        if ($application->entry_mode === 'de') {
            $this->saveStep($application, 'direct_entry', [
                'jamb_de_number' => strtoupper(str_replace(' ', '', (string) ($data['jamb_de_number'] ?? $data['jamb_registration'] ?? ''))),
                'previous_institution' => $data['previous_institution'] ?? '',
                'qualification_type' => $data['qualification_type'] ?? '',
                'qualification_title' => $data['qualification_title'] ?? '',
                'qualification_class' => $data['qualification_class'] ?? '',
                'qualification_year' => $data['qualification_year'] ?? '',
                'programme' => $data['previous_programme'] ?? '',
                'requested_entry_level' => $data['requested_entry_level'] ?? '',
            ]);
        }
        if ($application->entry_mode === 'transfer') {
            $this->saveStep($application, 'transfer_background', [
                'previous_university' => $data['previous_university'] ?? '',
                'previous_programme' => $data['previous_programme'] ?? '',
                'previous_student_id' => $data['previous_student_id'] ?? '',
                'credits_earned' => $data['credits_earned'] ?? '',
                'cgpa' => $data['cgpa'] ?? '',
                'reason_for_transfer' => $data['reason_for_transfer'] ?? '',
                'requested_entry_level' => $data['requested_entry_level'] ?? '',
                'has_transfer_approval' => $this->boolish($data['has_transfer_approval'] ?? null),
                'approval_reference' => $data['approval_reference'] ?? '',
            ]);
        }
        if ($application->entry_mode === 'pg') {
            $this->saveStep($application, 'pg_background', [
                'prior_degrees' => array_values(array_filter([[
                    'degree_title' => $data['prior_degree_title'] ?? '',
                    'institution' => $data['prior_institution'] ?? '',
                    'field_of_study' => $data['prior_field_of_study'] ?? '',
                    'class' => $data['prior_class'] ?? '',
                    'award_level' => $data['prior_award_level'] ?? '',
                    'year_awarded' => $data['prior_year_awarded'] ?? '',
                    'country' => $data['prior_country'] ?? '',
                ]], fn ($row) => filled($row['degree_title']) && filled($row['institution']))),
                'nysc_status' => $data['nysc_status'] ?? '',
                'nysc_number' => $data['nysc_number'] ?? '',
                'nysc_year' => $data['nysc_year'] ?? '',
                'nysc_exemption_reason' => $data['nysc_exemption_reason'] ?? '',
            ]);
            $this->saveStep($application, 'pg_research', [
                'research_interest' => $data['research_interest'] ?? '',
                'proposed_area' => $data['proposed_area'] ?? '',
                'statement_of_purpose' => $data['statement_of_purpose'] ?? '',
            ]);
            $referees = [];
            for ($i = 1; $i <= 3; $i++) {
                if (blank($data["referee_{$i}_name"] ?? null) && blank($data["referee_{$i}_email"] ?? null)) {
                    continue;
                }
                $referees[] = [
                    'name' => $data["referee_{$i}_name"] ?? '',
                    'email' => $data["referee_{$i}_email"] ?? '',
                    'institution' => $data["referee_{$i}_institution"] ?? '',
                    'position' => $data["referee_{$i}_position"] ?? '',
                    'phone' => $data["referee_{$i}_phone"] ?? '',
                ];
            }
            $this->saveStep($application, 'pg_referees', ['referees' => $referees]);
        }
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

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function academicPayload(array $data): array
    {
        return [
            'first_sitting' => $this->sittingPayload($data, 1),
            'second_sitting' => $this->sittingPayload($data, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function utmePayload(array $data): ?array
    {
        $utmeSubjects = [];
        for ($i = 1; $i <= 4; $i++) {
            if (blank($data["utme_subject_{$i}"] ?? null) && blank($data["utme_score_{$i}"] ?? null)) {
                continue;
            }
            $utmeSubjects[] = [
                'subject' => $data["utme_subject_{$i}"] ?? '',
                'score' => $data["utme_score_{$i}"] ?? '',
            ];
        }
        $choices = [];
        for ($i = 1; $i <= 2; $i++) {
            if (blank($data["utme_institution_{$i}"] ?? null)) {
                continue;
            }
            $choices[] = [
                'choice_order' => $i,
                'institution_name' => $data["utme_institution_{$i}"] ?? '',
                'programme_name' => $data["utme_programme_{$i}"] ?? '',
            ];
        }
        if ($utmeSubjects === [] && blank($data['utme_aggregate'] ?? null) && blank($data['utme_year'] ?? null)) {
            return null;
        }

        return [
            'aggregate' => $data['utme_aggregate'] ?? '',
            'course_choice' => $data['utme_course_choice'] ?? '',
            'exam_year' => $data['utme_year'] ?? '',
            'english_score' => $data['utme_english_score'] ?? '',
            'subjects' => $utmeSubjects,
            'institution_choices' => $choices,
        ];
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>|null
     */
    private function sittingPayload(array $data, int $sitting): ?array
    {
        if (blank($data["sitting{$sitting}_exam_type"] ?? null) && blank($data["sitting{$sitting}_exam_number"] ?? null)) {
            return $sitting === 1 ? [
                'exam_type' => '',
                'exam_center' => '',
                'exam_centre' => '',
                'exam_year' => '',
                'exam_number' => '',
                'results' => [],
            ] : null;
        }

        $results = [];
        for ($i = 1; $i <= 9; $i++) {
            $subjectName = trim((string) ($data["sitting{$sitting}_subject_{$i}_id"] ?? ''));
            $grade = trim((string) ($data["sitting{$sitting}_grade_{$i}"] ?? ''));
            if ($subjectName === '' && $grade === '') {
                continue;
            }
            if ($subjectName === '') {
                throw new RuntimeException("sitting{$sitting}_subject_{$i}_id is required when a grade is set. Copy the id from the O-level subjects sheet.");
            }
            $subject = $this->resolveOlevelSubject($subjectName, "sitting{$sitting}_subject_{$i}_id");
            $results[] = [
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'grade' => $grade,
            ];
        }

        return [
            'exam_type' => strtoupper((string) ($data["sitting{$sitting}_exam_type"] ?? '')),
            'exam_center' => $data["sitting{$sitting}_exam_centre"] ?? '',
            'exam_centre' => $data["sitting{$sitting}_exam_centre"] ?? '',
            'exam_year' => $data["sitting{$sitting}_exam_year"] ?? '',
            'exam_number' => $data["sitting{$sitting}_exam_number"] ?? '',
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function rowLooksComplete(array $data, string $entryMode): bool
    {
        if (blank($data['sitting1_exam_type'] ?? null) || blank($data['sitting1_subject_1_id'] ?? null)) {
            return false;
        }
        if ($entryMode === 'de' && blank($data['previous_institution'] ?? null)) {
            return false;
        }
        if ($entryMode === 'transfer' && blank($data['previous_university'] ?? null)) {
            return false;
        }
        if ($entryMode === 'pg' && blank($data['prior_degree_title'] ?? null)) {
            return false;
        }

        return true;
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
            throw new RuntimeException("Programme {$id} does not accept {$entryMode} applicants.");
        }

        return $program;
    }

    /**
     * @param  array<string, string>  $data
     * @return array{0: ?StateOfOrigin, 1: ?Lga}
     */
    private function resolveStateAndLga(array $data): array
    {
        $state = $this->resolveState($data['state_id'] ?? null);
        $lga = $this->resolveLga($data['lga_id'] ?? null);
        if ($state && $lga && (int) $lga->state_id !== (int) $state->state_id) {
            throw new RuntimeException("lga_id {$lga->lga_id} does not belong to state_id {$state->state_id}. Copy both from the States and LGAs sheets.");
        }
        if (! $state && $lga) {
            $state = $lga->state;
        }

        return [$state, $lga];
    }

    private function resolveState(?string $value): ?StateOfOrigin
    {
        $id = SpreadsheetImport::parseOptionalId($value, 'state_id');
        if ($id === null) {
            return null;
        }
        $state = StateOfOrigin::query()->find($id);
        if (! $state) {
            throw new RuntimeException("Unknown state_id: {$id}. Copy it from the States sheet.");
        }

        return $state;
    }

    private function resolveLga(?string $value): ?Lga
    {
        $id = SpreadsheetImport::parseOptionalId($value, 'lga_id');
        if ($id === null) {
            return null;
        }
        $lga = Lga::query()->find($id);
        if (! $lga) {
            throw new RuntimeException("Unknown lga_id: {$id}. Copy it from the LGAs sheet.");
        }

        return $lga;
    }

    private function resolveOlevelSubject(string $value, string $field): OlevelSubject
    {
        $id = SpreadsheetImport::parseId($value, $field);
        $subject = OlevelSubject::query()->find($id);
        if (! $subject) {
            throw new RuntimeException("Unknown {$field}: {$id}. Copy it from the O-level subjects sheet.");
        }

        return $subject;
    }

    private function assertNotDuplicate(string $email, string $nin, string $jamb, string $oldNumber, int $intakeId): void
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
        unset($intakeId);
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
            throw new \InvalidArgumentException('Unknown applicant category.');
        }

        return $entryMode;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Applicants') ?: $spreadsheet->getActiveSheet();

        return $sheet->toArray(null, true, true, false);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function indexHeaders(array $headers): array
    {
        $indexes = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $indexes[$header] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $indexes
     * @return array<string, string>
     */
    private function mapRow(array $row, array $indexes): array
    {
        $mapped = [];
        foreach ($indexes as $column => $index) {
            $mapped[$column] = trim((string) ($row[$index] ?? ''));
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function rowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $time = strtotime($value);

        return $time ? date('Y-m-d', $time) : $value;
    }

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y'], true);
    }

    private function country(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'Nigeria') === 0) {
            return 'Nigeria';
        }

        return strcasecmp($value, 'Non-Nigeria') === 0 ? 'Non-Nigeria' : 'Non-Nigeria';
    }
}
