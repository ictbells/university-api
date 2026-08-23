<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\FeeItem;
use App\Models\Intake;
use App\Models\Program;
use App\Models\Setting;
use App\Support\RegistrationCriteria;
use App\Services\AuditWriter;
use App\Services\InvoiceService;
use App\Services\Notifier;
use App\Services\PremblyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApplicationController extends Controller
{
    public function __construct(
        private InvoiceService $invoices,
        private AuditWriter $audit,
        private Notifier $notifier,
        private PremblyService $prembly,
    ) {}

    public function index(Request $request)
    {
        $query = Application::query()->with(['user', 'program', 'intake', 'applicationFeeInvoice', 'acceptanceFeeInvoice']);
        if ($request->filled('stage')) {
            $query->where('stage', $request->stage);
        }
        if ($request->filled('entry_mode')) {
            $query->where('entry_mode', $request->entry_mode);
        }
        if ($request->filled('entry_modes')) {
            $modes = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
            if ($modes !== []) {
                $query->whereIn('entry_mode', $modes);
            }
        }
        if ($request->user()->hasPermission('admissions.view')) {
            RegistrationCriteria::excludeRegisteredApplications($query);
        }
        if (! $request->user()->hasPermission('admissions.view')) {
            $query->where('user_id', $request->user()->id);
        }

        return $query->latest()->paginate(25);
    }

    public function show(Request $request, Application $application)
    {
        $this->authorizeView($request, $application);

        return $application->load(['user', 'program', 'intake', 'steps', 'documents', 'reviews.reviewer', 'applicationFeeInvoice', 'acceptanceFeeInvoice']);
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'entry_mode' => 'required|in:utme,de,jupeb,transfer,pg',
            'intake_id' => 'nullable|exists:intakes,id',
            'program_id' => 'nullable|exists:programs,id',
        ]);
        $intake = $data['intake_id']
            ? Intake::query()->with('term')->findOrFail($data['intake_id'])
            : Intake::query()->with('term')->where('entry_mode', $data['entry_mode'])->accepting()->firstOrFail();

        abort_unless($intake->entry_mode === $data['entry_mode'], 422, 'Entry mode does not match this application window.');
        abort_unless($intake->isAcceptingApplications(), 422, 'Applications are not open for this entry mode and session.');

        $existing = Application::query()->where('user_id', $request->user()->id)->where('intake_id', $intake->id)->first();
        if ($existing) {
            return $existing->load('applicationFeeInvoice');
        }

        $fee = FeeItem::query()->where('category', 'application_fee')->where('entry_mode', $data['entry_mode'])->where('is_active', true)->firstOrFail();
        $application = Application::query()->create([
            'user_id' => $request->user()->id,
            'intake_id' => $intake->id,
            'program_id' => $data['program_id'] ?? null,
            'entry_mode' => $data['entry_mode'],
            'stage' => 'awaiting_application_fee',
            'current_step' => null,
        ]);
        foreach (Application::FORM_STEPS as $step) {
            $application->steps()->create(['step_key' => $step, 'status' => 'pending', 'payload' => []]);
        }
        $invoice = $this->invoices->createForFee($request->user(), $fee, $application->id);
        $application->update(['application_fee_invoice_id' => $invoice->id]);
        $this->audit->record('application.started', 'Application started ('.$data['entry_mode'].')', 'admissions', 'application', $application->id, null, $application);

        return $application->fresh(['applicationFeeInvoice', 'steps']);
    }

    public function saveStep(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        if (! in_array($application->stage, ['fee_paid', 'form_in_progress'], true)) {
            return response()->json(['message' => 'This application is no longer editable.'], 422);
        }
        $data = $request->validate([
            'step_key' => 'required|in:'.implode(',', Application::FORM_STEPS),
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
        $before = $step->payload;
        $step->update(['payload' => $payload, 'status' => 'saved']);
        if ($data['step_key'] === 'programme_selection' && ! empty($payload['program_id'])) {
            $program = Program::query()->find($payload['program_id']);
            abort_unless(
                $program && $program->is_active && in_array($application->entry_mode, $program->entry_modes ?? [], true),
                422,
                'The selected programme is not available for your admission category.',
            );
        }
        $application->update([
            'stage' => 'form_in_progress',
            'current_step' => $data['step_key'],
            'program_id' => $payload['program_id'] ?? $application->program_id,
        ]);
        $this->audit->record('application.step_saved', 'Saved '.$data['step_key'], 'admissions', 'application', $application->id, $before, $payload);

        return $application->fresh('steps');
    }

    public function submit(Request $request, Application $application)
    {
        $this->authorizeOwner($request, $application);
        if (! $application->ninVerified()) {
            return response()->json(['message' => 'Verify your NIN before submitting your application.'], 422);
        }
        $missing = $application->steps()->where('status', 'pending')->pluck('step_key');
        if ($missing->isNotEmpty()) {
            return response()->json(['message' => 'Complete all steps before submitting.', 'missing' => $missing], 422);
        }
        $application->steps()->where('step_key', 'required_documents')->update(['status' => 'complete']);
        $application->update([
            'stage' => 'submitted',
            'submitted_at' => now(),
            'current_step' => 'required_documents',
        ]);
        $this->audit->record('application.submitted', 'Application submitted for screening', 'admissions', 'application', $application->id);
        $this->notifier->send($application->user, 'application_submitted', 'Application submitted', 'Your file is now in screening.', 'admissions', $application->id);

        return $application->fresh();
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
        $data = $request->validate([
            'doc_type' => 'required|string',
            'file' => 'required|file|max:5120|mimes:pdf,jpg,jpeg,png',
        ]);
        $path = $request->file('file')->store('applications/'.$application->id, 'public');
        $doc = $application->documents()->create([
            'doc_type' => $data['doc_type'],
            'path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]);
        $step = $application->steps()->where('step_key', 'required_documents')->first();
        if ($step) {
            $payload = $step->payload ?? [];
            $payload['files'][] = $doc->only(['id', 'doc_type', 'path']);
            $step->update(['payload' => $payload, 'status' => 'saved']);
        }

        return $doc;
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

        return response()->json($record->only(['id', 'nin', 'mapped_fields', 'verified_at']));
    }

    public function transition(Request $request, Application $application)
    {
        $data = $request->validate([
            'to_stage' => 'required|string',
            'decision' => 'nullable|string',
            'reason' => 'required_if:decision,rejected,withdrawn|nullable|string',
        ]);
        $to = $data['to_stage'];
        $map = Application::STAFF_STAGES;
        $expected = $map[$application->stage] ?? null;
        if ($data['decision'] === 'rejected') {
            $to = 'rejected';
        }
        $permission = Application::STAGE_PERMISSION[$to] ?? 'admissions.view';
        if ($to !== 'rejected' && ! $request->user()->hasPermission($permission)) {
            return response()->json(['message' => 'You cannot move the file to this stage.'], 403);
        }
        if ($to !== 'rejected' && $expected && $to !== $expected) {
            return response()->json(['message' => 'Invalid stage transition.'], 422);
        }
        $before = $application->stage;
        $application->reviews()->create([
            'reviewer_id' => $request->user()->id,
            'from_stage' => $before,
            'to_stage' => $to,
            'decision' => $data['decision'] ?? 'advanced',
            'reason' => $data['reason'] ?? null,
        ]);
        $application->update(['stage' => $to]);
        if ($to === 'offer_issued') {
            $this->issueOffer($application);
        }
        $this->audit->record('application.stage', "Moved from {$before} to {$to}", 'admissions', 'application', $application->id, ['stage' => $before], ['stage' => $to], $data['reason'] ?? null);
        $this->notifier->send($application->user, 'application_stage', 'Application update', 'Your application is now at '.str_replace('_', ' ', $to).'.', 'admissions', $application->id);

        return $application->fresh(['acceptanceFeeInvoice', 'reviews']);
    }

    private function issueOffer(Application $application): void
    {
        $application->update(['offer_reference' => 'OFF-'.now()->format('Y').'-'.Str::upper(Str::random(6))]);
        $fee = FeeItem::query()->where('category', 'acceptance_fee')->where('is_active', true)->first();
        if ($fee && ! $application->acceptance_fee_invoice_id) {
            $invoice = $this->invoices->createForFee($application->user, $fee, $application->id);
            $application->update([
                'acceptance_fee_invoice_id' => $invoice->id,
                'stage' => 'awaiting_acceptance_fee',
            ]);
        }
        $html = '<div style="font-family:sans-serif"><h2>'.Setting::getValue('university_name', 'Bells University of Technology').'</h2>'
            .'<p>Admission Offer '.$application->offer_reference.'</p>'
            .'<p>Congratulations. Pay the acceptance fee to complete student creation.</p></div>';
        Document::query()->create([
            'user_id' => $application->user_id,
            'type' => 'offer_letter',
            'title' => 'Admission Offer',
            'html_body' => $html,
            'status' => 'issued',
        ]);
    }

    private function authorizeOwner(Request $request, Application $application): void
    {
        if ($application->user_id !== $request->user()->id && ! $request->user()->hasPermission('admissions.view')) {
            abort(403);
        }
    }

    private function authorizeView(Request $request, Application $application): void
    {
        $this->authorizeOwner($request, $application);
    }
}
