<?php

namespace App\Http\Controllers;

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
use App\Support\ApplicationReference;
use App\Support\CandidateEligibility;
use App\Support\ProgrammeEligibility;
use App\Support\RegistrationCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
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

        return AcademicSession::query()
            ->with('semesters')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AcademicSession $session) => [
                'id' => $session->id,
                'session_label' => $session->label,
                'name' => $session->label,
                'is_current' => $session->semesters->contains(fn ($s) => $s->is_current),
            ]);
    }

    public function show(Request $request, Application $application)
    {
        $this->authorizeView($request, $application);
        $application->ensureFormSteps();
        $this->prembly->syncUserVerificationToApplication($request->user(), $application);
        $this->staffUpdates->refreshJambStatus($application);

        return $this->decorateFile($this->staffUpdates->freshFile($application));
    }

    public function staffUpdate(Request $request, Application $application)
    {
        $this->authorizeStaffEdit($request, $application);

        $data = $request->validate([
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:30',
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
            'first_sitting' => 'nullable|array',
            'second_sitting' => 'nullable|array',
            'prior_degrees' => 'nullable|array',
            'nysc_status' => 'nullable|string',
            'nysc_number' => 'nullable|string|max:80',
            'nysc_year' => 'nullable|string|max:10',
            'nysc_exemption_reason' => 'nullable|string|max:500',
            'professional_qualifications' => 'nullable|array',
            'research_interest' => 'nullable|string|max:2000',
            'proposed_area' => 'nullable|string|max:500',
            'statement_of_purpose' => 'nullable|string|max:8000',
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
        $html = $this->documents->admissionLetterHtml($application);
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

        abort_unless($intake->entry_mode === $data['entry_mode'], 422, 'Entry mode does not match this application window.');
        abort_unless($intake->isAcceptingApplications(), 422, 'Applications are not open for this entry mode and session.');

        $existing = Application::query()->where('user_id', $request->user()->id)->where('intake_id', $intake->id)->first();
        if ($existing) {
            $this->prembly->syncUserVerificationToApplication($request->user(), $existing);

            return $existing->fresh()->load(['applicationFeeInvoice', 'intake.term', 'steps', 'documents']);
        }

        try {
            $intake->applicationFeeAmount();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $application = Application::query()->create([
            'application_number' => ApplicationReference::generate(),
            'user_id' => $request->user()->id,
            'intake_id' => $intake->id,
            'program_id' => $data['program_id'] ?? null,
            'entry_mode' => $data['entry_mode'],
            'jamb_registration' => $data['jamb_registration'] ?? null,
            'jamb_status' => ! empty($data['jamb_registration'])
                ? (CandidateEligibility::findByJamb($data['jamb_registration']) ? 'validated' : 'pending')
                : null,
            'stage' => 'awaiting_application_fee',
            'current_step' => null,
        ]);

        if (! empty($data['jamb_registration'])) {
            $request->user()->update(['jamb_registration' => $data['jamb_registration']]);
        }

        foreach (Application::formSteps($data['entry_mode']) as $step) {
            $application->steps()->create(['step_key' => $step, 'status' => 'pending', 'payload' => []]);
        }
        $invoice = $this->invoices->createApplicationFeeInvoice($request->user(), $intake, $application->id);
        $application->update(['application_fee_invoice_id' => $invoice->id]);
        $this->prembly->syncUserVerificationToApplication($request->user(), $application);
        $this->audit->record('application.started', 'Application started ('.$data['entry_mode'].')', 'admissions', 'application', $application->id, null, $application);

        return $application->fresh(['applicationFeeInvoice', 'steps', 'documents', 'intake.term']);
    }

    public function saveStep(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        if (! in_array($application->stage, ['fee_paid', 'form_in_progress'], true)) {
            return response()->json(['message' => 'This application is no longer editable.'], 422);
        }
        $application->ensureFormSteps();
        $allowedSteps = Application::formSteps($application->entry_mode);
        $data = $request->validate([
            'step_key' => 'required|in:'.implode(',', $allowedSteps),
            'payload' => 'required|array',
        ]);
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
                'payload.blood_group' => 'required|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
                'payload.genotype' => 'required|string|in:AA,AS,AC,SS,SC,CC',
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
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.phone' => 'required|string|max:30',
                'payload.address' => 'required|string|max:500',
                'payload.declaration' => 'accepted',
            ])['payload'] + $payload;
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
                'payload.first_sitting.results' => 'required|array|min:1',
                'payload.first_sitting.results.*.subject_id' => 'required|integer|exists:olevel_subjects,id',
                'payload.first_sitting.results.*.subject_name' => 'nullable|string|max:120',
                'payload.first_sitting.results.*.grade' => 'required|string|max:10',
                'payload.second_sitting' => 'nullable|array',
                'payload.second_sitting.exam_type' => 'nullable|string|in:WAEC,NECO,GCE,NABTEB,Other',
                'payload.second_sitting.exam_center' => 'nullable|string|max:150',
                'payload.second_sitting.exam_year' => 'nullable|string|max:10',
                'payload.second_sitting.exam_number' => 'nullable|string|max:50',
                'payload.second_sitting.results' => 'nullable|array',
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
                        'payload.second_sitting.results' => 'required|array|min:1',
                        'payload.second_sitting.results.*.subject_id' => 'required|integer|exists:olevel_subjects,id',
                        'payload.second_sitting.results.*.grade' => 'required|string|max:10',
                    ]);
                    $payload['second_sitting']['results'] = $secondResults->values()->all();
                } else {
                    $payload['second_sitting'] = null;
                }
            }
        }
        if ($data['step_key'] === 'utme') {
            $payload = ApplicationFormSteps::validateUtme($request, $payload, true);
        }
        if ($data['step_key'] === 'programme_selection') {
            $request->merge(['payload' => $payload]);
            $payload = $request->validate([
                'payload.first_choice_program_id' => 'required|integer|exists:programs,id',
                'payload.second_choice_program_id' => 'nullable|integer|exists:programs,id|different:payload.first_choice_program_id',
            ])['payload'] + $payload;
            $payload['program_id'] = (int) $payload['first_choice_program_id'];
            if (empty($payload['second_choice_program_id'])) {
                $payload['second_choice_program_id'] = null;
            }

            $choices = ['first_choice_program_id' => 'first choice'];
            if (! empty($payload['second_choice_program_id'])) {
                $choices['second_choice_program_id'] = 'second choice';
            }
            foreach ($choices as $key => $label) {
                $program = Program::query()->find($payload[$key]);
                abort_unless(
                    $program && $program->is_active && in_array($application->entry_mode, $program->entry_modes ?? [], true),
                    422,
                    'The selected '.$label.' programme is not available for your admission category.',
                );
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
            $payload = ApplicationFormSteps::validatePgReferees($request, $payload);
        }
        $before = $step->payload;
        $step->update(['payload' => $payload, 'status' => 'saved']);
        $application->update([
            'stage' => 'form_in_progress',
            'current_step' => $data['step_key'],
            'program_id' => $payload['program_id'] ?? $application->program_id,
        ]);
        $this->audit->record('application.step_saved', 'Saved '.$data['step_key'], 'admissions', 'application', $application->id, $before, $payload);

        if ($data['step_key'] === 'pg_referees') {
            $this->referees->sync($application, $payload['referees'] ?? []);
        }

        return $this->decorateFile($application->fresh(['steps', 'applicationFeeInvoice', 'program', 'intake.term', 'refereeInvites']));
    }

    public function submit(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        $application->ensureFormSteps();
        if (! $application->ninVerified()) {
            return response()->json(['message' => 'Verify your NIN before submitting your application.'], 422);
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
        $path = $request->file('file')->store('applications/'.$application->id, 'public');
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

        abort_unless($path !== '' && Storage::disk('public')->exists($path), 404, 'Document file not found.');

        $filename = $document->original_name ?: basename($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';

        return Storage::disk('public')->response($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function streamPassport(Request $request, Application $application): BinaryFileResponse
    {
        $this->authorizeView($request, $application);

        return ApplicantPassport::fileResponseForApplication($application);
    }

    public function verifyNin(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        $data = $request->validate(['nin' => 'required|string']);
        if (! in_array($application->stage, ['fee_paid', 'form_in_progress'], true)
            && ! $request->user()->hasPermission('identity.verify_nin')) {
            return response()->json(['message' => 'NIN can only be verified during the form.'], 422);
        }
        $record = $this->prembly->verify($request->user(), $application, $data['nin']);

        return response()->json([
            ...$record->only(['id', 'nin', 'mapped_fields', 'verified_at']),
            'live' => $this->prembly->isLiveMapped($record->mapped_fields),
        ]);
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

                return $this->decorateFile($application->fresh(['acceptanceFeeInvoice', 'reviews']));
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
            $application->update(['offer_reference' => $this->documents->generateOfferReference()]);
        }
        if (! $application->acceptance_fee_invoice_id) {
            try {
                $application->loadMissing('intake');
                $intake = $application->intake;
                $amount = $this->invoices->resolveAcceptanceFeeAmount($intake, $acceptanceFeeAmount);
                if ($intake) {
                    $invoice = $this->invoices->createAcceptanceFeeInvoice(
                        $application->user,
                        $intake,
                        $application->id,
                        $amount,
                    );
                } else {
                    $fee = FeeItem::query()->where('category', 'acceptance_fee')->where('is_active', true)->first();
                    $invoice = $fee
                        ? $this->invoices->createForFee($application->user, $fee, $application->id, null, $amount)
                        : null;
                }
                if ($invoice) {
                    $application->update([
                        'acceptance_fee_invoice_id' => $invoice->id,
                        'stage' => 'awaiting_acceptance_fee',
                    ]);
                }
            } catch (\Throwable $e) {
                if ($acceptanceFeeAmount !== null) {
                    throw $e;
                }
                // Offer letter still issues; acceptance invoice can be created once fee is set.
            }
        } elseif ($acceptanceFeeAmount !== null && $application->acceptanceFeeInvoice) {
            $invoice = $this->invoices->updateAcceptanceFeeInvoice(
                $application->acceptanceFeeInvoice,
                $acceptanceFeeAmount,
            );
            $application->update(['acceptance_fee_invoice_id' => $invoice->id]);
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
        $this->authorizeOwner($request, $application);
        abort_unless((int) $invite->application_id === (int) $application->id, 404);
        $this->referees->resend($application, $invite);

        return response()->json(['referees' => $this->referees->statusFor($application->fresh('refereeInvites'))]);
    }

    /**
     * @return JsonResponse|Application
     */
    private function decorateFile(Application $application)
    {
        $application->loadMissing(['program.workflowTemplate.stages', 'steps', 'refereeInvites', 'documents']);
        $application->setAttribute('eligibility', ProgrammeEligibility::forApplication($application));
        $application->setAttribute('workflow', $this->workflows->snapshot($application));
        $application->setAttribute('referee_invites', $this->referees->statusFor($application));
        $application->setAttribute(
            'required_documents',
            AdmissionEntryRules::requiredDocuments((string) $application->entry_mode, $application),
        );

        return $application;
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
