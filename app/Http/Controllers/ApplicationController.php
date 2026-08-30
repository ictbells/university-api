<?php

namespace App\Http\Controllers;

use App\Mail\AdmissionOfferMail;
use App\Models\AcademicSession;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Document;
use App\Models\FeeItem;
use App\Models\Intake;
use App\Models\Program;
use App\Models\RefereeInvite;
use App\Services\ApplicationDocumentService;
use App\Services\ApplicationExportService;
use App\Services\ApplicationStaffUpdateService;
use App\Services\ApplicationStartService;
use App\Services\AuditWriter;
use App\Services\InvoiceService;
use App\Services\Notifier;
use App\Services\PremblyService;
use App\Services\RefereeInviteService;
use App\Services\WorkflowEngine;
use App\Support\AdmissionEntryRules;
use App\Support\ApplicantPassport;
use App\Support\ApplicationFormSteps;
use App\Support\ApplicationListQuery;
use App\Support\CandidateEligibility;
use App\Support\ProgrammeEligibility;
use App\Support\PgResearchWordLimits;
use App\Support\PhoneNumber;
use App\Support\RegistrationCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(
        private InvoiceService $invoices,
        private AuditWriter $audit,
        private Notifier $notifier,
        private PremblyService $prembly,
        private ApplicationExportService $exports,
        private ApplicationDocumentService $documents,
        private ApplicationStaffUpdateService $staffUpdates,
        private WorkflowEngine $workflows,
        private RefereeInviteService $referees,
        private ApplicationStartService $applicationStart,
    ) {}

    public function index(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $paginator = ApplicationListQuery::fromRequest($request)->paginate($perPage);

        if (! $request->user()?->hasPermission('admissions.view')) {
            return $paginator;
        }

        $payload = $paginator->toArray();
        $payload['summary'] = ApplicationListQuery::stageSummary($request);
        $payload['data'] = collect($paginator->items())->map(function ($application) {
            $row = $application->toArray();
            $row['eligibility'] = ProgrammeEligibility::forApplication($application);
            $row['workflow'] = $this->workflows->snapshot($application);
            if ($application->entry_mode === 'transfer') {
                $transfer = ProgrammeEligibility::step($application, 'transfer_background');
                $row['previous_university'] = $transfer['previous_university'] ?? null;
                $row['credit_assessment_complete'] = $application->transferAssessmentComplete();
            }

            return $row;
        })->all();

        return response()->json($payload);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->hasPermission('admissions.view'), 403);

        $data = $request->validate([
            'format' => 'required|in:pdf,excel,word',
            'title' => 'nullable|string|max:120',
            'reference_kind' => 'nullable|in:jamb,application_number',
            'stage' => 'nullable|string',
            'entry_mode' => 'nullable|string',
            'entry_modes' => 'nullable',
            'fee_status' => 'nullable|string',
            'academic_term_id' => 'nullable|integer|exists:academic_terms,id',
            'academic_session_id' => 'nullable|integer|exists:academic_sessions,id',
            'intake_id' => 'nullable|integer|exists:intakes,id',
            'session' => 'nullable|string',
            'program_id' => 'nullable|integer|exists:programs,id',
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'college_id' => 'nullable|integer|exists:faculties,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'search' => 'nullable|string',
        ]);

        $applications = ApplicationListQuery::fromRequest($request)
            ->limit(ApplicationExportService::MAX_ROWS)
            ->get();

        return $this->exports->export(
            $data['format'],
            $applications,
            $data['title'] ?? 'Applications report',
            ApplicationListQuery::filterSummary($request),
            $data['reference_kind'] ?? 'application_number',
        );
    }

    public function sessions(Request $request)
    {
        abort_unless($request->user()->hasPermission('admissions.view'), 403);

        $modes = [];
        if ($request->filled('entry_mode')) {
            $modes[] = (string) $request->entry_mode;
        }
        if ($request->filled('entry_modes')) {
            $extra = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
            $modes = array_values(array_unique(array_merge($modes, $extra)));
        }

        $sessionIds = Application::query()
            ->whereNotNull('academic_session_id')
            ->when($modes !== [], fn ($query) => $query->whereIn('entry_mode', $modes))
            ->distinct()
            ->pluck('academic_session_id');

        $intakeSessionIds = Intake::query()
            ->with('term')
            ->when($modes !== [], fn ($query) => $query->whereIn('entry_mode', $modes))
            ->get()
            ->map(fn (Intake $intake) => $intake->academicSessionId())
            ->filter();

        $ids = $sessionIds->merge($intakeSessionIds)->unique()->filter()->values();
        $intakes = Intake::query()
            ->with('term')
            ->when($modes !== [], fn ($query) => $query->whereIn('entry_mode', $modes))
            ->get();

        return AcademicSession::query()
            ->whereIn('id', $ids)
            ->orderByDesc('id')
            ->get()
            ->map(function (AcademicSession $session) use ($intakes) {
                $accepting = $intakes->contains(
                    fn (Intake $intake) => $intake->academicSessionId() === (int) $session->id
                        && $intake->isAcceptingApplications()
                );

                return [
                    'id' => $session->id,
                    'session_label' => $session->label,
                    'name' => $session->label,
                    'admission_session_label' => $session->label,
                    'is_open' => $accepting,
                    'is_current' => $accepting,
                ];
            })
            ->values();
    }

    public function show(Request $request, Application $application)
    {
        $this->authorizeView($request, $application);
        $application->ensureFormSteps();
        $this->prembly->syncUserVerificationToApplication($request->user(), $application);
        $this->staffUpdates->refreshJambStatus($application);
        $this->ensureAcceptanceInvoiceIfOffered($application);

        return $this->decorateFile($this->staffUpdates->freshFile($application));
    }

    public function staffUpdate(Request $request, Application $application)
    {
        $this->authorizeStaffEdit($request, $application);

        $wordLimits = PgResearchWordLimits::all();
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:30',
            'alternate_phone' => ['nullable', 'string', 'max:30', new PhoneNumber],
            'jamb_registration' => 'nullable|string|max:20',
            'first_name' => 'required|string|max:80',
            'middle_name' => 'nullable|string|max:80',
            'last_name' => 'required|string|max:80',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'marital_status' => 'nullable|string|max:50',
            'religion' => 'nullable|string|max:80',
            'country' => 'nullable|string|max:80',
            'state' => 'nullable|string|max:100',
            'state_id' => 'nullable|integer',
            'lga' => 'nullable|string|max:100',
            'lga_id' => 'nullable|integer',
            'address' => 'nullable|string|max:500',
            'blood_group' => 'nullable|string|max:10',
            'genotype' => 'nullable|string|max:10',
            'has_medical_condition' => 'nullable|boolean',
            'medical_condition_details' => 'nullable|string|max:2000',
            'next_of_kin' => 'nullable|string|max:120',
            'next_of_kin_relationship' => 'nullable|string|max:80',
            'next_of_kin_phone' => 'nullable|string|max:30',
            'next_of_kin_email' => 'nullable|email|max:120',
            'next_of_kin_address' => 'nullable|string|max:500',
            'sponsor_name' => 'nullable|string|max:120',
            'sponsor_relationship' => 'nullable|string|max:80',
            'sponsor_phone' => 'nullable|string|max:30',
            'sponsor_email' => 'nullable|email|max:120',
            'sponsor_address' => 'nullable|string|max:500',
            'other_qualifications' => 'nullable|string|max:2000',
            'utme' => 'nullable|array',
            ...$this->sittingValidationRules('first_sitting'),
            ...$this->sittingValidationRules('second_sitting'),
            'prior_degrees' => 'nullable|array',
            'nysc_status' => 'nullable|string',
            'nysc_number' => 'nullable|string|max:12',
            'nysc_year' => 'nullable|string|max:10',
            'nysc_exemption_reason' => 'nullable|string|max:500',
            'professional_qualifications' => 'nullable|array',
            'research_interest' => 'nullable|string|max:'.PgResearchWordLimits::charMax(2000, $wordLimits['pg_research_interest_max_words']),
            'proposed_area' => 'nullable|string|max:500',
            'statement_of_purpose' => 'nullable|string|max:'.PgResearchWordLimits::charMax(8000, $wordLimits['pg_statement_of_purpose_max_words']),
            'publications' => 'nullable|array',
            'supervisor_preferences' => 'nullable|array',
            'referees' => 'nullable|array',
            'first_choice_college_id' => 'nullable|integer',
            'first_choice_department_id' => 'nullable|integer',
            'first_choice_program_id' => 'required|integer|exists:programs,id',
            'second_choice_college_id' => 'nullable|integer',
            'second_choice_department_id' => 'nullable|integer',
            'second_choice_program_id' => 'nullable|integer|exists:programs,id',
            'direct_entry' => 'nullable|array',
            'transfer_background' => 'nullable|array',
            'credit_assessment' => 'nullable|array',
        ]);
        PgResearchWordLimits::assertPayload($data, '');

        if (AdmissionEntryRules::nabtebCombinedWithSecondSitting(
            is_array($data['first_sitting'] ?? null) ? $data['first_sitting'] : null,
            is_array($data['second_sitting'] ?? null) ? $data['second_sitting'] : null,
        )) {
            return response()->json([
                'message' => "NABTEB uses one sitting only. Remove the second sitting or choose a different exam type.",
            ], 422);
        }

        if (! AdmissionEntryRules::allowsSecondProgramme((string) ($application->entry_mode ?? ''))) {
            if (! empty($data['second_choice_program_id'])) {
                return response()->json([
                    'message' => 'JUPEB applicants may select only one programme.',
                ], 422);
            }
            $data['second_choice_program_id'] = null;
            $data['second_choice_college_id'] = null;
            $data['second_choice_department_id'] = null;
        }
        if (($application->entry_mode ?? '') === 'jupeb' && ! empty($data['first_choice_program_id'])) {
            $program = Program::query()->with('department.faculty')->find($data['first_choice_program_id']);
            if ($program && ! $program->isOfferedAtJupebCentre()) {
                return response()->json([
                    'message' => 'JUPEB applicants can only choose a programme offered at a JUPEB centre.',
                ], 422);
            }
        }

        return $this->officeGate(
            'admissions.staff_update',
            $application,
            $data + ['application_id' => $application->id],
            'Update application file',
            fn () => $this->decorateFile($this->staffUpdates->update($application, $data)),
            \App\Support\OfficeApprovalCatalog::admissionsNavKey($application->entry_mode ?? $application->channel ?? null),
        );
    }

    public function formPrint(Request $request, Application $application): Response
    {
        $this->authorizeView($request, $application);
        $html = $this->documents->formHtml($application);
        $filename = 'application-form-'.($application->application_number ?: $application->id).'.html';
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        }

        return response($html, 200, $headers);
    }

    public function offerLetter(Request $request, Application $application): Response
    {
        $this->authorizeView($request, $application);
        $this->ensureAcceptanceInvoiceIfOffered($application);
        $html = $this->documents->admissionLetterHtml($application->fresh(['acceptanceFeeInvoice']));
        $filename = 'admission-letter-'.str_replace('/', '-', (string) $application->offer_reference).'.html';
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        }

        return response($html, 200, $headers);
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'entry_mode' => 'required|in:utme,de,jupeb,transfer,pg',
            'intake_id' => 'nullable|exists:intakes,id',
            'program_id' => 'nullable|exists:programs,id',
            'jamb_registration' => [
                Rule::requiredIf(fn () => AdmissionEntryRules::requiresJambRegistration((string) $request->input('entry_mode'))),
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'jamb_registration')->ignore($request->user()->id),
            ],
        ]);

        if ($request->filled('jamb_registration')) {
            $data['jamb_registration'] = strtoupper(str_replace(' ', '', (string) $data['jamb_registration']));
        }

        $intake = $data['intake_id']
            ? Intake::query()->with('term')->findOrFail($data['intake_id'])
            : Intake::query()->with('term')->where('entry_mode', $data['entry_mode'])->get()
                ->first(fn (Intake $candidate) => $candidate->isAcceptingApplications());

        abort_unless($intake instanceof Intake, 422, 'Applications are not open for this entry mode and session.');

        abort_unless($intake->entry_mode === $data['entry_mode'], 422, 'Entry mode does not match this application session.');
        abort_unless($intake->isAcceptingApplications(), 422, 'Applications are not open for this entry mode and session.');

        try {
            return $this->applicationStart->start(
                $request->user(),
                $intake,
                $data['jamb_registration'] ?? null,
                isset($data['program_id']) ? (int) $data['program_id'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function saveStep(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        $application->ensureFormSteps();
        $allowedSteps = Application::formSteps($application->entry_mode);
        $data = $request->validate([
            'step_key' => 'required|in:'.implode(',', $allowedSteps),
            'payload' => 'required|array',
        ]);
        $formWindow = in_array($application->stage, ['fee_paid', 'form_in_progress'], true);
        $closed = in_array($application->stage, ['rejected', 'withdrawn', 'matriculated'], true);
        if (! $formWindow) {
            if ($data['step_key'] !== 'pg_referees' || $closed) {
                return response()->json(['message' => 'This application is no longer editable.'], 422);
            }
        }
        $step = $application->steps()->where('step_key', $data['step_key'])->firstOrFail();
        $payload = $data['payload'];
        if ($data['step_key'] !== 'biodata' && ! $application->ninVerified()) {
            return response()->json(['message' => 'Verify your NIN before continuing the application form.'], 422);
        }
        if ($data['step_key'] === 'biodata') {
            $existing = $step->payload ?? [];
            if (! ($existing['nin_locked'] ?? false)) {
                return response()->json(['message' => 'Verify your NIN before saving biodata.'], 422);
            }
            foreach (['nin', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'photo_path'] as $locked) {
                if (isset($existing[$locked])) {
                    $payload[$locked] = $existing[$locked];
                }
            }
            $payload['nin_locked'] = true;
        }
        if ($data['step_key'] === 'personal_details') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.marital_status' => 'required|string|max:50',
                'payload.religion' => 'required|string|max:80',
                'payload.country' => 'required|in:Nigeria,Non-Nigeria',
                'payload.state' => 'required|string|max:100',
                'payload.state_id' => 'nullable|integer',
                'payload.lga' => 'required|string|max:100',
                'payload.lga_id' => 'nullable|integer',
            ])['payload'] + $payload;

            if (($payload['country'] ?? '') === 'Nigeria') {
                if (empty($payload['state_id'])) {
                    return response()->json(['message' => 'Select a Nigerian state.'], 422);
                }
                if (empty($payload['lga_id'])) {
                    return response()->json(['message' => 'Select an LGA for the chosen state.'], 422);
                }
            } else {
                $payload['state_id'] = null;
                $payload['lga_id'] = null;
            }
        }
        if ($data['step_key'] === 'health_information') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.blood_group' => 'required|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-,Other',
                'payload.genotype' => 'required|string|in:AA,AS,AC,SS,SC,CC,Other',
                'payload.has_medical_condition' => 'required|boolean',
                'payload.medical_condition_details' => 'nullable|string|max:2000',
            ])['payload'] + $payload;
            if (! empty($payload['has_medical_condition']) && blank($payload['medical_condition_details'] ?? null)) {
                return response()->json(['message' => 'Provide health condition details when a medical condition is indicated.'], 422);
            }
        }
        if ($data['step_key'] === 'next_of_kin') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.next_of_kin' => 'required|string|max:120',
                'payload.next_of_kin_relationship' => 'required|string|max:80',
                'payload.next_of_kin_phone' => 'required|string|max:30',
                'payload.next_of_kin_email' => 'nullable|email|max:120',
                'payload.next_of_kin_address' => 'required|string|max:500',
            ])['payload'] + $payload;
        }
        if ($data['step_key'] === 'sponsor') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.sponsor_name' => 'required|string|max:120',
                'payload.sponsor_relationship' => 'required|string|max:80',
                'payload.sponsor_phone' => 'required|string|max:30',
                'payload.sponsor_email' => 'nullable|email|max:120',
                'payload.sponsor_address' => 'required|string|max:500',
            ])['payload'] + $payload;
        }
        if ($data['step_key'] === 'application_form') {
            $existing = is_array($step->payload) ? $step->payload : [];
            $ninPhone = trim((string) ($existing['phone'] ?? $application->user?->phone ?? ''));
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.alternate_phone' => ['required', 'string', 'max:30', new PhoneNumber],
                'payload.address' => 'required|string|max:500',
                'payload.declaration' => 'accepted',
            ], [
                'payload.alternate_phone.required' => 'Enter an alternate phone number. This is required even if your NIN already has a phone number.',
            ])['payload'] + $payload;
            $payload['phone'] = $ninPhone !== '' ? $ninPhone : null;
            $payload['alternate_phone'] = PhoneNumber::normalize($payload['alternate_phone'] ?? null);
            $application->user?->update(['alternate_phone' => $payload['alternate_phone']]);
        }
        if ($data['step_key'] === 'academic_qualifications') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.other_qualifications' => 'nullable|string|max:2000',
                'payload.first_sitting' => 'required|array',
                'payload.first_sitting.exam_type' => 'required|string|in:WAEC,NECO,GCE,NABTEB,Other',
                'payload.first_sitting.exam_center' => 'required|string|max:150',
                'payload.first_sitting.exam_year' => 'required|string|max:10',
                'payload.first_sitting.exam_number' => 'required|string|max:50',
                'payload.first_sitting.results' => 'required|array|min:1|max:9',
                'payload.first_sitting.results.*.subject_id' => 'required|integer|exists:olevel_subjects,id',
                'payload.first_sitting.results.*.subject_name' => 'nullable|string|max:120',
                'payload.first_sitting.results.*.grade' => 'required|string|max:10',
                'payload.second_sitting' => 'nullable|array',
                'payload.second_sitting.exam_type' => 'nullable|string|in:WAEC,NECO,GCE,NABTEB,Other',
                'payload.second_sitting.exam_center' => 'nullable|string|max:150',
                'payload.second_sitting.exam_year' => 'nullable|string|max:10',
                'payload.second_sitting.exam_number' => 'nullable|string|max:50',
                'payload.second_sitting.results' => 'nullable|array|max:9',
                'payload.second_sitting.results.*.subject_id' => 'nullable|integer|exists:olevel_subjects,id',
                'payload.second_sitting.results.*.subject_name' => 'nullable|string|max:120',
                'payload.second_sitting.results.*.grade' => 'nullable|string|max:10',
            ])['payload'] + $payload;

            // UTME lives on the dedicated `utme` step; ignore legacy nested blob.
            unset($payload['utme']);

            $second = $payload['second_sitting'] ?? null;
            if (is_array($second)) {
                $secondHasMeta = filled($second['exam_type'] ?? null)
                    || filled($second['exam_center'] ?? null)
                    || filled($second['exam_year'] ?? null)
                    || filled($second['exam_number'] ?? null);
                $secondResults = collect($second['results'] ?? [])->filter(
                    fn ($row) => filled($row['subject_id'] ?? null) || filled($row['grade'] ?? null)
                );
                if ($secondHasMeta || $secondResults->isNotEmpty()) {
                    $request->merge(['payload' => ['second_sitting' => $second]]);
                    $request->validate([
                        'payload.second_sitting.exam_type' => 'required|string|in:WAEC,NECO,GCE,NABTEB,Other',
                        'payload.second_sitting.exam_center' => 'required|string|max:150',
                        'payload.second_sitting.exam_year' => 'required|string|max:10',
                        'payload.second_sitting.exam_number' => 'required|string|max:50',
                        'payload.second_sitting.results' => 'required|array|min:1|max:9',
                        'payload.second_sitting.results.*.subject_id' => 'required|integer|exists:olevel_subjects,id',
                        'payload.second_sitting.results.*.grade' => 'required|string|max:10',
                    ]);
                    $payload['second_sitting']['results'] = $secondResults->values()->all();
                } else {
                    $payload['second_sitting'] = null;
                }
            }
            if (AdmissionEntryRules::nabtebCombinedWithSecondSitting(
                is_array($payload['first_sitting'] ?? null) ? $payload['first_sitting'] : null,
                is_array($payload['second_sitting'] ?? null) ? $payload['second_sitting'] : null,
            )) {
                return response()->json([
                    'message' => "NABTEB uses one sitting only. Remove the second sitting or choose a different exam type.",
                ], 422);
            }
        }
        if ($data['step_key'] === 'utme') {
            $payload = ApplicationFormSteps::validateUtme($request, $payload, true);
        }
        if ($data['step_key'] === 'programme_selection') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.first_choice_program_id' => 'required|integer|min:1|exists:programs,id',
                'payload.second_choice_program_id' => 'nullable|integer|exists:programs,id|different:payload.first_choice_program_id',
            ])['payload'] + $payload;
            $payload['program_id'] = (int) $payload['first_choice_program_id'];
            if (! AdmissionEntryRules::allowsSecondProgramme((string) $application->entry_mode)) {
                if (! empty($payload['second_choice_program_id'])) {
                    return response()->json([
                        'message' => 'JUPEB applicants may select only one programme.',
                    ], 422);
                }
                $payload['second_choice_program_id'] = null;
                $payload['second_choice_college_id'] = null;
                $payload['second_choice_department_id'] = null;
            } elseif (empty($payload['second_choice_program_id'])) {
                $payload['second_choice_program_id'] = null;
                $payload['second_choice_college_id'] = null;
                $payload['second_choice_department_id'] = null;
            }

            $choices = ['first_choice_program_id' => 'first choice'];
            if (! empty($payload['second_choice_program_id'])) {
                $choices['second_choice_program_id'] = 'second choice';
            }
            foreach ($choices as $key => $label) {
                $program = Program::query()->with('department.faculty')->find($payload[$key]);
                if (! $program || ! $program->isOffered() || ! $program->acceptsEntryMode($application->entry_mode)) {
                    return response()->json([
                        'message' => 'The selected '.$label.' programme is not available for your admission category.',
                    ], 422);
                }
                if ($application->entry_mode === 'jupeb' && ! $program->isOfferedAtJupebCentre()) {
                    return response()->json([
                        'message' => 'JUPEB applicants can only choose a programme offered at a JUPEB centre.',
                    ], 422);
                }
                $prefix = $key === 'first_choice_program_id' ? 'first_choice' : 'second_choice';
                $payload[$prefix.'_program_id'] = $program->id;
                $payload[$prefix.'_department_id'] = $program->department_id;
                $payload[$prefix.'_college_id'] = $program->department?->faculty_id;
            }
        }
        if ($data['step_key'] === 'direct_entry') {
            $payload = ApplicationFormSteps::validateDirectEntry($request, $payload);
            if (! empty($payload['jamb_de_number']) && ! $application->jamb_registration) {
                $application->update([
                    'jamb_registration' => $payload['jamb_de_number'],
                    'jamb_status' => CandidateEligibility::findByJamb($payload['jamb_de_number']) ? 'validated' : 'pending',
                ]);
            }
        }
        if ($data['step_key'] === 'transfer_background') {
            $payload = ApplicationFormSteps::validateTransferBackground($request, $payload);
        }
        if ($data['step_key'] === 'pg_background') {
            $payload = ApplicationFormSteps::validatePgBackground($request, $payload);
        }
        if ($data['step_key'] === 'pg_research') {
            $choiceId = ProgrammeEligibility::firstChoiceId($application);
            $program = Program::query()->find($choiceId);
            $payload = ApplicationFormSteps::validatePgResearch($request, $payload, $program);
        }
        if ($data['step_key'] === 'pg_referees') {
            $payload['referees'] = $this->referees->preserveSubmittedRows($application, $payload['referees'] ?? []);
            $payload = ApplicationFormSteps::validatePgReferees($request, $payload);
        }
        $before = $step->payload;
        $step->update(['payload' => $payload, 'status' => 'saved']);
        if ($formWindow) {
            $application->update([
                'stage' => 'form_in_progress',
                'current_step' => $data['step_key'],
                'program_id' => $payload['program_id'] ?? $application->program_id,
            ]);
        }
        $this->audit->record('application.step_saved', 'Saved '.$data['step_key'], 'admissions', 'application', $application->id, $before, $payload);

        if ($data['step_key'] === 'pg_referees') {
            $this->referees->sync($application, $payload['referees'] ?? []);
        }

        return $this->decorateFile($application->fresh(['steps', 'applicationFeeInvoice', 'program', 'intake.term', 'refereeInvites']));
    }

    public function submit(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        if (! $application->applicationWindowOpen()) {
            return response()->json([
                'message' => Intake::CLOSED_SUBMIT_MESSAGE,
                'code' => Intake::INTAKE_NOT_ACCEPTING_CODE,
            ], 422);
        }
        $application->ensureFormSteps();
        if (! $application->ninVerified()) {
            return response()->json(['message' => 'Verify your NIN before submitting your application.'], 422);
        }
        if ($blocked = $this->rejectIfProgrammeMissing($application)) {
            return $blocked;
        }
        $missing = $application->steps()->where('status', 'pending')->pluck('step_key');
        if ($missing->isNotEmpty()) {
            return response()->json(['message' => 'Complete all steps before submitting.', 'missing' => $missing], 422);
        }
        $missingDocs = AdmissionEntryRules::missingRequiredDocuments($application);
        if ($missingDocs !== []) {
            return response()->json([
                'message' => 'Upload all required documents before submitting: '.implode(', ', $missingDocs).'.',
                'missing_documents' => $missingDocs,
            ], 422);
        }
        $request->validate(
            ['submission_notice_accepted' => ['required', 'accepted']],
            ['submission_notice_accepted.accepted' => 'Confirm the submission notice before submitting your application.'],
        );
        $application->steps()->where('step_key', 'required_documents')->update(['status' => 'complete']);
        $application->update([
            'stage' => 'submitted',
            'submitted_at' => now(),
            'current_step' => 'required_documents',
        ]);
        $this->audit->record('application.submitted', 'Application submitted for screening', 'admissions', 'application', $application->id);
        $this->notifier->send($application->user, 'application_submitted', 'Application submitted', 'Your file is now in screening.', 'admissions', $application->id);
        $this->workflows->ensureAdmissionRun($application->fresh());

        return $this->decorateFile($application->fresh());
    }

    public function uploadDocument(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        if (! in_array($application->stage, ['fee_paid', 'form_in_progress'], true)) {
            return response()->json(['message' => 'Pay the application fee before uploading documents.'], 422);
        }
        if (! $application->ninVerified()) {
            return response()->json(['message' => 'Verify your NIN before uploading documents.'], 422);
        }
        $allowed = collect(AdmissionEntryRules::requiredDocuments((string) $application->entry_mode, $application))
            ->pluck('key')
            ->push('supporting')
            ->unique()
            ->all();
        $data = $request->validate([
            'doc_type' => 'required|string|in:'.implode(',', $allowed),
            'file' => 'required|file|max:5120|mimes:pdf,jpg,jpeg,png',
        ]);
        $path = $request->file('file')->store('applications/'.$application->id, \App\Support\AppStorage::diskName());
        $existing = $application->documents()->where('doc_type', $data['doc_type'])->first();
        if ($existing) {
            $existing->update([
                'path' => $path,
                'original_name' => $request->file('file')->getClientOriginalName(),
            ]);
            $doc = $existing->fresh();
        } else {
            $doc = $application->documents()->create([
                'doc_type' => $data['doc_type'],
                'path' => $path,
                'original_name' => $request->file('file')->getClientOriginalName(),
            ]);
        }
        $step = $application->steps()->where('step_key', 'required_documents')->first();
        if ($step) {
            $payload = $step->payload ?? [];
            $payload['files'] = $application->documents()->get(['id', 'doc_type', 'path', 'original_name'])->toArray();
            $missing = AdmissionEntryRules::missingRequiredDocuments($application->fresh('documents'));
            $step->update([
                'payload' => $payload,
                'status' => $missing === [] ? 'saved' : 'saved',
            ]);
        }

        return $doc;
    }

    public function streamDocument(Request $request, Application $application, ApplicationDocument $document): StreamedResponse|Response
    {
        $this->authorizeView($request, $application);
        abort_unless((int) $document->application_id === (int) $application->id, 404);

        $path = ltrim((string) $document->path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        abort_unless($path !== '' && \App\Support\AppStorage::exists($path), 404, 'Document file not found.');

        $filename = $document->original_name ?: basename($path);
        $mime = \App\Support\AppStorage::mimeType($path);

        return \App\Support\AppStorage::response($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function streamPassport(Request $request, Application $application): BinaryFileResponse|StreamedResponse
    {
        $this->authorizeView($request, $application);

        return ApplicantPassport::fileResponseForApplication($application);
    }

    public function verifyNin(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        $data = $request->validate(['nin' => 'required|string']);
        $formWindow = in_array($application->stage, ['fee_paid', 'form_in_progress'], true);
        $staffCanVerify = $request->user()->hasPermission('identity.verify_nin');
        $studentSelfVerify = $application->user_id === $request->user()->id
            && $request->user()->isStudent()
            && ! $application->ninVerified();
        if (! $formWindow && ! $staffCanVerify && ! $studentSelfVerify) {
            return response()->json(['message' => 'NIN can only be verified during the form or after you sign in as a student.'], 422);
        }
        $record = $this->prembly->verify($request->user(), $application, $data['nin']);

        return response()->json([
            ...$record->only(['id', 'nin', 'mapped_fields', 'verified_at']),
            'live' => $this->prembly->isLiveMapped($record->mapped_fields),
        ]);
    }

    public function resyncNin(Request $request, Application $application)
    {
        $this->authorizeStaffEdit($request, $application);
        abort_unless($request->user()->hasPermission('identity.verify_nin'), 403);
        $this->prembly->resyncFromNin($application, $request->user());

        return $this->decorateFile($application->fresh(['steps', 'documents', 'user', 'student', 'program']));
    }

    public function transition(Request $request, Application $application)
    {
        $data = $request->validate([
            'to_stage' => 'required|string',
            'decision' => 'nullable|string',
            'reason' => 'required_if:decision,rejected,withdrawn|nullable|string',
            'acceptance_fee_amount' => 'nullable|numeric|min:0',
        ]);
        $to = $data['to_stage'];
        $before = $application->stage;
        $navKey = \App\Support\OfficeApprovalCatalog::admissionsNavKey($application->entry_mode ?? $application->channel ?? null);

        return $this->officeGate(
            'admissions.transition',
            $application,
            $data + ['application_id' => $application->id],
            'Advance application to '.$to,
            function () use ($application, $to, $before, $data, $request) {
                $application = $this->workflows->advanceApplication(
                    $application,
                    $to,
                    $request->user(),
                    $data['decision'] ?? null,
                    $data['reason'] ?? null,
                );
                if ($this->workflows->issuesOffer($application->stage)) {
                    $this->issueOffer($application, isset($data['acceptance_fee_amount']) ? (float) $data['acceptance_fee_amount'] : null);
                }
                $this->audit->record('application.stage', "Moved from {$before} to {$application->stage}", 'admissions', 'application', $application->id, ['stage' => $before], ['stage' => $application->stage], $data['reason'] ?? null);
                $this->notifier->send($application->user, 'application_stage', 'Application update', 'Your application is now at '.str_replace('_', ' ', $application->stage).'.', 'admissions', $application->id);

                return $this->decorateFile($application->fresh(['acceptanceFeeInvoice', 'reviews', 'latestReview']));
            },
            $navKey,
        );
    }

    public function revert(Request $request, Application $application)
    {
        $this->authorizeStaffEdit($request, $application);
        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);
        $before = $application->stage;
        $navKey = \App\Support\OfficeApprovalCatalog::admissionsNavKey($application->entry_mode ?? $application->channel ?? null);

        return $this->officeGate(
            'admissions.revert',
            $application,
            $data + ['application_id' => $application->id],
            'Revert last application decision',
            function () use ($application, $before, $data, $request) {
                try {
                    $application = $this->workflows->revertLastDecision(
                        $application,
                        $request->user(),
                        $data['reason'] ?? null,
                    );
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                $this->audit->record(
                    'application.stage_reverted',
                    "Reverted from {$before} to {$application->stage}",
                    'admissions',
                    'application',
                    $application->id,
                    ['stage' => $before],
                    ['stage' => $application->stage],
                    $data['reason'] ?? null,
                );
                $this->notifier->send(
                    $application->user,
                    'application_stage',
                    'Application update',
                    'Your application was returned to '.str_replace('_', ' ', $application->stage).'.',
                    'admissions',
                    $application->id,
                );

                return $this->decorateFile($application->fresh(['acceptanceFeeInvoice', 'reviews', 'latestReview']));
            },
            $navKey,
        );
    }

    public function updateAcceptanceFee(Request $request, Application $application)
    {
        abort_unless($request->user()->hasPermission('admissions.offer'), 403);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        $application->loadMissing('acceptanceFeeInvoice');
        $invoice = $application->acceptanceFeeInvoice;
        abort_unless($invoice, 422, 'No acceptance fee invoice exists for this application.');

        $before = $invoice->toArray();

        return $this->officeGate(
            'admissions.update_acceptance_fee',
            $application,
            $data + ['application_id' => $application->id],
            'Update acceptance fee',
            function () use ($application, $invoice, $data, $before) {
                $invoice = $this->invoices->updateAcceptanceFeeInvoice($invoice, (float) $data['amount']);
                $this->audit->record(
                    'application.acceptance_fee_updated',
                    'Acceptance fee amount updated',
                    'admissions',
                    'application',
                    $application->id,
                    $before,
                    $invoice->toArray(),
                );

                return $application->fresh(['acceptanceFeeInvoice']);
            },
            \App\Support\OfficeApprovalCatalog::admissionsNavKey($application->entry_mode ?? $application->channel ?? null),
        );
    }

    private function issueOffer(Application $application, ?float $acceptanceFeeAmount = null): void
    {
        if (! $application->offer_reference) {
            $application->update(['offer_reference' => $this->documents->generateOfferReference($application)]);
        }
        try {
            $this->invoices->ensureAcceptanceFeeInvoice($application, $acceptanceFeeAmount);
        } catch (\Throwable $e) {
            if ($acceptanceFeeAmount !== null) {
                throw $e;
            }
            report($e);
        }
        $application = $application->fresh([
            'user',
            'program.department.faculty',
            'intake.term',
            'steps',
            'acceptanceFeeInvoice',
        ]);
        $html = $this->documents->admissionLetterHtml($application);
        Document::query()->updateOrCreate(
            [
                'user_id' => $application->user_id,
                'type' => 'offer_letter',
                'title' => 'Admission Offer',
            ],
            [
                'html_body' => $html,
                'status' => 'issued',
            ]
        );
        $this->sendOfferEmail($application);
    }

    private function sendOfferEmail(Application $application): void
    {
        $user = $application->user;
        if (! $user?->email) {
            return;
        }

        try {
            Mail::to($user->email)->send(new AdmissionOfferMail($application));
        } catch (\Throwable $exception) {
            Log::warning('application.offer_email_failed', [
                'application_id' => $application->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function eligibility(Request $request, Application $application)
    {
        $this->authorizeView($request, $application);
        $application->loadMissing(['steps', 'program', 'refereeInvites']);
        $programId = $request->integer('program_id') ?: ProgrammeEligibility::firstChoiceId($application);
        $programs = Program::query()
            ->where('is_active', true)
            ->whereJsonContains('entry_modes', $application->entry_mode)
            ->when($programId, fn ($q) => $q->orWhere('id', $programId))
            ->orderBy('name')
            ->get();

        return response()->json([
            'current' => ProgrammeEligibility::forApplication($application),
            'programs' => $programs->map(fn (Program $program) => [
                'id' => $program->id,
                'name' => $program->name,
                ...ProgrammeEligibility::evaluate($program, $application),
            ])->values(),
        ]);
    }

    public function resendReferee(Request $request, Application $application, RefereeInvite $invite)
    {
        abort_unless((int) $invite->application_id === (int) $application->id, 404);
        $this->authorizeOwner($request, $application);

        abort_if($invite->status === 'submitted', 422, 'This referee has already submitted a letter.');

        $data = $request->validate([
            'email' => 'nullable|email|max:190',
            'name' => 'nullable|string|max:120',
            'institution' => 'nullable|string|max:190',
            'position' => 'nullable|string|max:120',
        ]);
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;
        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        $institution = isset($data['institution']) ? trim((string) $data['institution']) : null;
        $position = isset($data['position']) ? trim((string) $data['position']) : null;
        $before = ['id' => $invite->id, 'email' => $invite->email, 'name' => $invite->name];
        $changed = ($email && $email !== strtolower((string) $invite->email))
            || ($name !== null && $name !== '' && $name !== (string) $invite->name)
            || ($institution !== null && $institution !== '' && $institution !== (string) $invite->institution)
            || ($position !== null && $position !== '' && $position !== (string) $invite->position_title);

        if ($changed) {
            $invite = $this->referees->updateContact($application, $invite, array_filter([
                'email' => $email,
                'name' => $name,
                'institution' => $institution,
                'position' => $position,
            ], fn ($value) => $value !== null && $value !== ''));
        }

        $this->referees->resend($application, $invite);
        $this->audit->record(
            $changed ? 'application.referee_email_changed' : 'application.referee_resent',
            $changed
                ? 'Changed referee email and resent the recommendation request'
                : 'Resent referee recommendation request',
            'admissions',
            'application',
            $application->id,
            $before,
            ['id' => $invite->id, 'email' => $invite->email, 'name' => $invite->name],
        );

        return response()->json([
            'referees' => $this->referees->statusFor($application->fresh('refereeInvites')),
            'message' => $changed
                ? 'Referee email updated and invite sent.'
                : 'Invite resent.',
        ]);
    }

    private function ensureAcceptanceInvoiceIfOffered(Application $application): void
    {
        $this->invoices->ensureAcceptanceInvoiceIfOffered($application);
    }

    /**
     * @return JsonResponse|Application
     */
    private function decorateFile(Application $application)
    {
        $application->loadMissing(['program.workflowTemplate.stages', 'steps', 'refereeInvites', 'documents', 'latestReview', 'academicSession', 'intake.term']);
        $application->setAttribute('eligibility', ProgrammeEligibility::forApplication($application));
        $application->setAttribute('workflow', $this->workflows->snapshot($application));
        $application->setAttribute('referee_invites', $this->referees->statusFor($application));
        $application->setAttribute(
            'required_documents',
            AdmissionEntryRules::requiredDocuments((string) $application->entry_mode, $application),
        );

        $application->setAttribute('pg_word_limits', PgResearchWordLimits::all());
        $application->setAttribute('application_window_open', $application->applicationWindowOpen());

        return $application;
    }

    /**
     * @return JsonResponse|null
     */
    private function rejectIfProgrammeMissing(Application $application): ?JsonResponse
    {
        $programId = ProgrammeEligibility::firstChoiceId($application);
        $program = $programId ? Program::query()->with('department.faculty')->find($programId) : null;
        if (
            ! $program
            || ! $program->isOffered()
            || ! $program->acceptsEntryMode($application->entry_mode)
        ) {
            return response()->json([
                'message' => 'Select a programme before submitting your application.',
            ], 422);
        }
        if ($application->entry_mode === 'jupeb' && ! $program->isOfferedAtJupebCentre()) {
            return response()->json([
                'message' => 'JUPEB applicants can only choose a programme offered at a JUPEB centre.',
            ], 422);
        }

        if ((int) $application->program_id !== (int) $program->id) {
            $application->update(['program_id' => $program->id]);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function sittingValidationRules(string $prefix): array
    {
        return [
            $prefix => 'nullable|array',
            $prefix.'.exam_type' => 'nullable|string|in:WAEC,NECO,GCE,NABTEB,Other',
            $prefix.'.exam_center' => 'nullable|string|max:150',
            $prefix.'.exam_year' => 'nullable|string|max:10',
            $prefix.'.exam_number' => 'nullable|string|max:50',
            $prefix.'.results' => 'nullable|array|max:9',
            $prefix.'.results.*.subject_id' => 'nullable|integer',
            $prefix.'.results.*.subject_name' => 'nullable|string|max:120',
            $prefix.'.results.*.grade' => 'nullable|string|max:10',
        ];
    }

    private function authorizeOwner(Request $request, Application $application): void
    {
        if ($application->user_id !== $request->user()->id && ! $this->canStaffAccessFile($request, $application)) {
            abort(403);
        }
    }

    private function authorizeView(Request $request, Application $application): void
    {
        $this->authorizeOwner($request, $application);
    }

    private function authorizeStaffEdit(Request $request, Application $application): void
    {
        abort_unless($this->canStaffAccessFile($request, $application), 403);
    }

    private function canStaffAccessFile(Request $request, Application $application): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ($user->hasPermission('admissions.view')) {
            return true;
        }

        return $user->hasPermission('registrations.view')
            && RegistrationCriteria::studentsQuery()
                ->where(function ($query) use ($application) {
                    $query->where('students.application_id', $application->id);
                    if ($application->student_id) {
                        $query->orWhere('students.id', $application->student_id);
                    }
                })
                ->exists();
    }
}
