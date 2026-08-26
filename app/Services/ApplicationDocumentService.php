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

        $studyLevel = $application->program?->study_level === 'postgraduate'
            ? 'POSTGRADUATE DEGREE PROGRAMME'
            : 'UNDERGRADUATE DEGREE PROGRAMME';

        $programmeKind = $application->program?->study_level === 'postgraduate'
            ? 'a Postgraduate Degree Programme'
            : 'a Bachelor Degree Programme';

        return view('documents.admission-letter', [
            'institution' => $this->institution(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'application' => $application,
            'full_name' => Str::upper($fullName),
            'salutation_name' => Str::upper((string) $firstName),
            'address' => $contact['address'] ?? $biodata['address'] ?? null,
            'college' => $application->program?->department?->faculty?->name ?: 'College',
            'programme' => $application->program?->name ?: 'your chosen programme',
            'programme_kind' => $programmeKind,
            'session' => $session,
            'study_level' => $studyLevel,
            'offer_reference' => $application->offer_reference,
            'letter_date' => $issuedAt->format('jS F, Y'),
            'acceptance_amount' => $amount,
            'acceptance_amount_words' => NairaWords::phrase($amount, only: false),
            'show_jamb_documents' => in_array((string) $application->entry_mode, AdmissionEntryRules::JAMB_ENTRY_MODES, true),
            'portal_url' => (string) Setting::getValue(
                'application_portal_url',
                'https://apply.bellsuniversityportal.com'
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

    public function generateOfferReference(): string
    {
        do {
            $ref = 'BUT/AD/'.now()->format('Y').Str::upper(Str::random(10));
        } while (Application::query()->where('offer_reference', $ref)->exists());

        return $ref;
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
