<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Campus;
use App\Models\Program;
use App\Models\Setting;
use App\Support\AdmissionEntryRules;
use App\Support\ApplicantPassport;
use App\Support\InstitutionLogo;
use App\Support\NairaWords;
use App\Support\PgAdmissionSignature;
use App\Support\RegistrarSignature;
use App\Support\TranscriptRequestSettings;
use Illuminate\Support\Str;

class ApplicationDocumentService
{
    public function formHtml(Application $application): string
    {
        $application->loadMissing([
            'user',
            'program.department.faculty',
            'intake.term',
            'steps',
            'documents',
        ]);

        $profile = $application->mergedProfilePayload();
        $biodata = $profile;
        $contact = $this->stepPayload($application, 'application_form');
        $academic = $this->normalizeAcademicPayload($this->stepPayload($application, 'academic_qualifications'));
        $utmeStep = $this->stepPayload($application, 'utme');
        if (! empty($utmeStep['utme']) && is_array($utmeStep['utme'])) {
            $academic['utme'] = $utmeStep['utme'];
        }
        $programmeStep = $this->stepPayload($application, 'programme_selection');
        $firstChoiceId = $programmeStep['first_choice_program_id']
            ?? $programmeStep['first_choice_program_id']
            ?? $application->program_id;
        $secondChoiceId = $programmeStep['second_choice_program_id']
            ?? $programmeStep['second_choice_program_id']
            ?? null;

        $firstProgram = Program::query()->with('department.faculty')->find($firstChoiceId) ?? $application->program;
        $secondProgram = $secondChoiceId ? Program::query()->with('department.faculty')->find($secondChoiceId) : null;

        $fullName = trim(collect([
            $biodata['first_name'] ?? null,
            $biodata['middle_name'] ?? null,
            $biodata['last_name'] ?? null,
        ])->filter()->implode(' ')) ?: ($application->user?->name ?? '—');

        return view('documents.application-form', [
            'institution' => $this->institution(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'application' => $application,
            'full_name' => Str::upper($fullName),
            'biodata' => $biodata,
            'contact' => $contact,
            'academic' => $academic,
            'pg_background' => $this->stepPayload($application, 'pg_background'),
            'pg_research' => $this->stepPayload($application, 'pg_research'),
            'pg_referees' => $this->stepPayload($application, 'pg_referees'),
            'direct_entry' => $this->stepPayload($application, 'direct_entry'),
            'transfer_background' => $this->stepPayload($application, 'transfer_background'),
            'credit_assessment' => $this->stepPayload($application, 'credit_assessment'),
            'college' => $firstProgram?->department?->faculty?->name,
            'department' => $firstProgram?->department?->name,
            'programme' => $firstProgram?->name,
            'first_choice' => $firstProgram?->name ?: $application->program?->name,
            'second_choice' => $secondProgram?->name,
            'first_choice_college' => $firstProgram?->department?->faculty?->name,
            'first_choice_department' => $firstProgram?->department?->name,
            'second_choice_college' => $secondProgram?->department?->faculty?->name,
            'second_choice_department' => $secondProgram?->department?->name,
            'documents' => $application->documents,
            'photo_data_uri' => ApplicantPassport::dataUriForApplication($application),
            'generated_at' => now()->format('d M Y, h:i A'),
        ])->render();
    }

    public function admissionLetterHtml(Application $application): string
    {
        $application->loadMissing([
            'user',
            'program.department.faculty',
            'intake.term',
            'steps',
            'acceptanceFeeInvoice',
        ]);

        abort_unless($application->offer_reference, 422, 'No admission offer has been issued for this application.');

        $biodata = $application->mergedProfilePayload();
        $contact = $this->stepPayload($application, 'application_form');

        $firstName = $biodata['first_name']
            ?? Str::of($application->user?->name ?? 'Applicant')->explode(' ')->first();
        $fullName = trim(collect([
            $biodata['first_name'] ?? null,
            $biodata['middle_name'] ?? null,
            $biodata['last_name'] ?? null,
        ])->filter()->implode(' ')) ?: ($application->user?->name ?? 'Applicant');

        $fee = $application->acceptanceFeeInvoice;
        if ($fee && ! in_array($fee->status, ['unpaid', 'partial', 'paid'], true)) {
            $fee = null;
        }
        if (! $fee) {
            try {
                $amount = app(InvoiceService::class)->resolveAcceptanceFeeAmount($application->intake);
            } catch (\Throwable) {
                $amount = (float) ($application->intake?->acceptanceFeeAmount() ?? 0);
            }
        } else {
            $amount = (float) $fee->amount;
        }

        $issuedAt = $application->updated_at ?? now();
        $session = $application->intake?->term?->session_label
            ?: Setting::getValue('current_session_label', now()->format('Y').'/'.(now()->format('Y') + 1));

        $isPostgraduate = $this->isPostgraduateApplication($application);
        $studyLevel = $isPostgraduate
            ? 'POSTGRADUATE DEGREE PROGRAMME'
            : 'UNDERGRADUATE DEGREE PROGRAMME';

        $programmeKind = $isPostgraduate
            ? 'Postgraduate Degree Programme'
            : 'Bachelor Degree Programme';

        $registrar = TranscriptRequestSettings::all();
        $view = (string) $application->entry_mode === 'jupeb'
            ? 'documents.admission-letter-jupeb'
            : ($isPostgraduate ? 'documents.admission-letter-pg' : 'documents.admission-letter');

        $institution = $this->institution();
        if ($isPostgraduate) {
            $institution['office'] = (string) Setting::getValue(
                'pg_admission_office_title',
                'College of Postgraduate Studies',
            );
        }

        $lastName = trim((string) ($biodata['last_name'] ?? ''));
        if ($lastName === '') {
            $parts = preg_split('/\s+/', trim($fullName)) ?: [];
            $lastName = (string) (end($parts) ?: $firstName);
        }

        $givenNames = trim(collect([
            $biodata['first_name'] ?? $firstName,
            $biodata['middle_name'] ?? null,
        ])->filter()->map(fn ($part) => Str::title(Str::lower((string) $part)))->implode(' '));
        $recipientName = trim(Str::upper($lastName).($givenNames !== '' ? ' '.$givenNames : ''));

        $address = $contact['address'] ?? $biodata['address'] ?? null;
        $acceptanceWords = NairaWords::phrase($amount, only: false);

        return view($view, [
            'institution' => $institution,
            'institution_with_city' => $this->institutionNameWithOta(),
            'letterhead_name' => $this->letterheadName(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'signature_data_uri' => $isPostgraduate
                ? PgAdmissionSignature::dataUri()
                : RegistrarSignature::dataUri(),
            'registrar_name' => $registrar['registrar_name'] !== ''
                ? $registrar['registrar_name']
                : 'Lamidi S. Tafa (Mr.)',
            'registrar_title' => $registrar['registrar_title'],
            'signatory_name' => $isPostgraduate
                ? ($registrar['pg_signatory_name'] !== ''
                    ? $registrar['pg_signatory_name']
                    : 'Olugbenga A. Adelowo')
                : ($registrar['registrar_name'] !== '' ? $registrar['registrar_name'] : 'Lamidi S. Tafa (Mr.)'),
            'signatory_title' => $isPostgraduate
                ? $registrar['pg_signatory_title']
                : $registrar['registrar_title'],
            'application' => $application,
            'entry_mode' => (string) $application->entry_mode,
            'is_direct_entry' => (string) $application->entry_mode === 'de',
            'full_name' => Str::upper($fullName),
            'recipient_name' => $recipientName !== '' ? $recipientName : Str::upper($fullName),
            'salutation_name' => Str::title(Str::lower((string) $firstName)),
            'salutation_surname' => Str::upper($lastName),
            'honorific' => $this->pgHonorific($biodata),
            'address' => $address,
            'address_lines' => $this->addressLines(is_string($address) ? $address : null),
            'college' => $application->program?->department?->faculty?->name ?: 'College',
            'department' => $application->program?->department?->name ?: 'the Department',
            'programme' => $application->program?->name ?: 'your chosen programme',
            'programme_pursuit' => $this->pgProgrammePursuit($application),
            'programme_label' => $this->jupebProgrammeLabel($application),
            'programme_kind' => $programmeKind,
            'session' => $session,
            'jupeb_exam_year' => $this->sessionEndYear((string) $session),
            'study_level' => $studyLevel,
            'offer_reference' => $this->formatOfferReference($application),
            'letter_date' => $issuedAt->format('jS F, Y'),
            'acceptance_amount' => $amount,
            'acceptance_amount_words' => $acceptanceWords,
            'acceptance_amount_words_lower' => Str::lower($acceptanceWords),
            'show_jamb_documents' => in_array((string) $application->entry_mode, AdmissionEntryRules::JAMB_ENTRY_MODES, true),
            'portal_url' => (string) Setting::getValue(
                'application_portal_url',
                'https://student.bellsuniversity.edu.ng'
            ),
            'fees_url' => (string) Setting::getValue(
                'school_fees_url',
                'https://www.bellsuniversity.edu.ng/admissions/schools-fees/'
            ),
            'dress_code_url' => (string) Setting::getValue(
                'dress_code_url',
                'https://www.bellsuniversity.edu.ng/academic-activities-at-bells-university/bells-university-student-life/'
            ),
            'generated_at' => now()->format('d M Y, h:i A'),
        ])->render();
    }

    public function generateOfferReference(Application $application): string
    {
        $application->loadMissing(['user', 'intake.term']);
        $base = $this->formatOfferReference($application);
        $ref = $base;
        $n = 1;
        while (Application::query()->where('offer_reference', $ref)->where('id', '!=', $application->id)->exists()) {
            $n++;
            $ref = $base.'-'.$n;
        }

        return $ref;
    }

    public function formatOfferReference(Application $application): string
    {
        $application->loadMissing(['user', 'intake.term', 'program.department.faculty']);
        if ((string) $application->entry_mode === 'jupeb') {
            $year = $this->sessionEndYear((string) ($application->intake?->term?->session_label ?? ''));
            $serial = $this->offerSerial($application, 4);

            return 'BUT/AD/JFS/'.$year.'/JU/'.$serial;
        }

        $code = $this->offerCollegeCode($application);
        if ($this->isPostgraduateApplication($application)) {
            $year = str_pad(substr($this->offerReferenceYear($application), -3), 3, '0', STR_PAD_LEFT);

            return 'BUT/COLPGS.'.$this->offerSerial($application, 5).'/'.$code.'/'.$year;
        }

        $year = substr($this->offerReferenceYear($application), -2);
        $suffix = $this->offerReferenceSuffix($application);

        return 'BUT/AD/'.$code.'/'.$year.'/'.$suffix;
    }

    private function offerCollegeCode(Application $application): string
    {
        $code = strtoupper((string) preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            (string) ($application->program?->department?->faculty?->code ?? ''),
        ));
        if ($code !== '') {
            return $code;
        }

        return $application->program?->study_level === 'postgraduate' ? 'PG' : 'UG';
    }

    private function isPostgraduateApplication(Application $application): bool
    {
        return (string) $application->entry_mode === 'pg'
            || $application->program?->study_level === 'postgraduate';
    }

    private function offerSerial(Application $application, int $width = 5): string
    {
        $source = (string) ($application->application_number ?: $application->id);
        if (preg_match('/(\d+)\s*$/', $source, $matches)) {
            return str_pad($matches[1], $width, '0', STR_PAD_LEFT);
        }

        return str_pad((string) $application->id, $width, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $biodata
     */
    private function pgHonorific(array $biodata): string
    {
        $title = trim((string) ($biodata['title'] ?? $biodata['honorific'] ?? ''));
        if ($title !== '') {
            $normalized = Str::title(Str::lower(rtrim($title, '.')));

            return in_array($normalized, ['Mr', 'Mrs', 'Ms', 'Dr', 'Prof'], true)
                ? $normalized.'.'
                : $normalized;
        }

        $gender = strtolower(trim((string) ($biodata['gender'] ?? '')));
        if (in_array($gender, ['female', 'f', 'woman'], true)) {
            $marital = strtolower(trim((string) ($biodata['marital_status'] ?? '')));

            return in_array($marital, ['single', 'unmarried'], true) ? 'Miss' : 'Mrs.';
        }

        return 'Mr.';
    }

    /**
     * @return list<string>
     */
    private function addressLines(?string $address): array
    {
        $text = trim((string) $address);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }

    private function pgProgrammePursuit(Application $application): string
    {
        $name = trim((string) ($application->program?->name ?: 'your chosen programme'));
        $award = strtoupper(trim((string) ($application->program?->award_type ?: '')));
        $expanded = $this->expandPgAwardLabel($award, $name);
        if ($expanded !== '' && strcasecmp($expanded, $name) !== 0) {
            return $expanded.' ('.$name.')';
        }

        return $name;
    }

    private function expandPgAwardLabel(string $award, string $name): string
    {
        $labels = [
            'PGD' => 'Postgraduate Diploma',
            'PGDE' => 'Postgraduate Diploma in Education',
            'M.SC' => 'Master of Science',
            'MSC' => 'Master of Science',
            'M.ENG' => 'Master of Engineering',
            'MENG' => 'Master of Engineering',
            'MBA' => 'Master of Business Administration',
            'M.PHIL' => 'Master of Philosophy',
            'MPHIL' => 'Master of Philosophy',
            'PH.D' => 'Doctor of Philosophy',
            'PHD' => 'Doctor of Philosophy',
        ];
        $label = $labels[$award] ?? '';
        if ($label === '') {
            return '';
        }
        if (stripos($name, $label) === 0) {
            return $name;
        }

        $stripped = trim((string) preg_replace(
            '/^(PGD|PGDE|M\.?\s*Sc|M\.?\s*Eng|MBA|M\.?\s*Phil|Ph\.?\s*D)\s+/i',
            '',
            $name,
        ));
        if ($stripped === '' || strcasecmp($stripped, $name) === 0) {
            return $label.' in '.$name;
        }

        return $label.' in '.$stripped;
    }

    private function jupebProgrammeLabel(Application $application): string
    {
        $name = trim((string) ($application->program?->name ?: 'JUPEB'));
        $name = trim((string) preg_replace('/\s+foundation(\s+programme)?$/i', '', $name));

        return $name !== '' ? $name : 'JUPEB';
    }

    private function letterheadName(): string
    {
        return strtoupper($this->institutionNameWithOta());
    }

    private function institutionNameWithOta(): string
    {
        $name = trim((string) Setting::getValue('university_name', 'Bells University of Technology'));
        if ($name === '') {
            $name = 'Bells University of Technology';
        }
        if (! str_contains(strtoupper($name), 'OTA')) {
            $name .= ', Ota';
        }

        return $name;
    }

    private function sessionEndYear(string $session): string
    {
        if (preg_match('/(\d{4})\s*\/\s*(\d{2,4})/', $session, $matches)) {
            $end = $matches[2];

            return strlen($end) === 2 ? substr($matches[1], 0, 2).$end : $end;
        }
        if (preg_match('/(\d{4})/', $session, $matches)) {
            return (string) ((int) $matches[1] + 1);
        }

        return now()->addYear()->format('Y');
    }

    private function offerReferenceYear(Application $application): string
    {
        $label = (string) ($application->intake?->term?->session_label ?? '');
        if (preg_match('/^(\d{4})/', $label, $matches)) {
            return $matches[1];
        }

        return now()->format('Y');
    }

    private function offerReferenceSuffix(Application $application): string
    {
        $jamb = null;
        if (AdmissionEntryRules::requiresJambRegistration((string) $application->entry_mode)) {
            $jamb = $application->jamb_registration ?: $application->user?->jamb_registration;
        }

        $value = strtoupper(preg_replace('/\s+/', '', (string) ($jamb ?: $application->application_number ?: $application->id)) ?? '');
        if ($value === '') {
            $value = (string) $application->id;
        }

        if ((string) $application->entry_mode === 'de' && ! str_ends_with($value, 'DE')) {
            $value .= 'DE';
        }

        return $value;
    }

    /**
     * @return array{name: string, motto: string, address: string, contact: string, office: string}
     */
    private function institution(): array
    {
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->first()
            ?? Campus::query()->orderBy('id')->first();

        return [
            'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
            'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
            'office' => (string) Setting::getValue('registrar_office_title', 'Office of the Registrar'),
            'address' => trim(collect([
                $campus?->address,
                $campus?->city,
            ])->filter()->implode(', '))
                ?: 'KM 8, Idiroko Road, Benja Village P.M.B 1015, Ota, Ogun State',
            'contact' => (string) Setting::getValue('university_contact', 'Telephone: 07087138753'),
        ];
    }

    /**
     * @param  array<string, mixed>  $academic
     * @return array<string, mixed>
     */
    private function normalizeAcademicPayload(array $academic): array
    {
        if (empty($academic['first_sitting']) && ! empty($academic['first_sitting'])) {
            $academic['first_sitting'] = $academic['first_sitting'];
        }
        if (empty($academic['second_sitting']) && ! empty($academic['second_sitting'])) {
            $academic['second_sitting'] = $academic['second_sitting'];
        }

        if (! empty($academic['first_sitting']) || ! empty($academic['second_sitting'])) {
            $academic['first_sitting'] = $this->normalizeSitting($academic['first_sitting'] ?? null);
            $academic['second_sitting'] = $this->normalizeSitting($academic['second_sitting'] ?? null);

            return $academic;
        }

        if (! empty($academic['olevel_results']) && is_array($academic['olevel_results'])) {
            $academic['first_sitting'] = $this->normalizeSitting([
                'exam_type' => $academic['exam_type'] ?? $academic['exam_type'] ?? null,
                'exam_center' => $academic['exam_center'] ?? $academic['exam_center'] ?? null,
                'exam_year' => $academic['exam_year'] ?? null,
                'exam_number' => $academic['exam_number'] ?? null,
                'results' => $academic['olevel_results'],
            ]);
        }

        return $academic;
    }

    /**
     * @param  array<string, mixed>|null  $sitting
     * @return array<string, mixed>|null
     */
    private function normalizeSitting(?array $sitting): ?array
    {
        if (! $sitting) {
            return null;
        }

        $sitting['exam_type'] = $sitting['exam_type'] ?? $sitting['examType'] ?? null;
        $sitting['exam_center'] = $sitting['exam_center']
            ?? $sitting['exam_centre']
            ?? $sitting['examCenter']
            ?? null;
        $sitting['exam_year'] = $sitting['exam_year'] ?? null;
        $sitting['exam_number'] = $sitting['exam_number'] ?? null;
        $sitting['results'] = array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            $row['subject_name'] = $row['subject_name'] ?? $row['subject_name'] ?? $row['subject'] ?? 'Subject';

            return $row;
        }, is_array($sitting['results'] ?? null) ? $sitting['results'] : []);

        return $sitting;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepPayload(Application $application, string $stepKey): array
    {
        $payload = $application->steps->firstWhere('step_key', $stepKey)?->payload;

        return is_array($payload) ? $payload : [];
    }
}
