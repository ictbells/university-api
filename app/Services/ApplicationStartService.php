<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Intake;
use App\Models\User;
use App\Support\ApplicationReference;
use App\Support\CandidateEligibility;

class ApplicationStartService
{
    public function __construct(
        private InvoiceService $invoices,
        private AuditWriter $audit,
        private PremblyService $prembly,
    ) {}

    public function start(User $user, Intake $intake, ?string $jambRegistration = null, ?int $programId = null): Application
    {
        $intake->loadMissing('term');
        abort_unless($intake->isAcceptingApplications(), 422, 'Applications are not open for this entry mode and session.');

        $jamb = $jambRegistration !== null && $jambRegistration !== ''
            ? strtoupper(str_replace(' ', '', $jambRegistration))
            : null;

        CandidateEligibility::assertQualifiedForIntake($intake, $jamb);

        $sessionId = $intake->academicSessionId();
        $existing = Application::query()
            ->where('user_id', $user->id)
            ->where('intake_id', $intake->id)
            ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
            ->first();
        if ($existing) {
            $this->prembly->syncUserVerificationToApplication($user, $existing);

            return $existing->fresh()->load(['applicationFeeInvoice', 'intake.term', 'steps', 'documents']);
        }

        $this->invoices->resolveApplicationFeeAmount($intake);

        $application = Application::query()->create([
            'application_number' => ApplicationReference::generate(),
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'academic_session_id' => $sessionId,
            'program_id' => $programId,
            'entry_mode' => $intake->entry_mode,
            'jamb_registration' => $jamb,
            'jamb_status' => $jamb
                ? (CandidateEligibility::findByJamb($jamb, $intake->term?->session_label) ? 'validated' : 'pending')
                : null,
            'stage' => 'awaiting_application_fee',
            'current_step' => null,
        ]);

        if ($jamb) {
            $user->update(['jamb_registration' => $jamb]);
        }

        foreach (Application::formSteps($intake->entry_mode) as $step) {
            $application->steps()->create(['step_key' => $step, 'status' => 'pending', 'payload' => []]);
        }
        $invoice = $this->invoices->createApplicationFeeInvoice($user, $intake, $application->id);
        $application->update(['application_fee_invoice_id' => $invoice->id]);
        $this->prembly->syncUserVerificationToApplication($user, $application);
        $this->audit->record('application.started', 'Application started ('.$intake->entry_mode.')', 'admissions', 'application', $application->id, null, $application);

        return $application->fresh(['applicationFeeInvoice', 'steps', 'documents', 'intake.term']);
    }
}
