<?php

namespace App\Services;

use App\Mail\ReturningApplicationCredentialsMail;
use App\Models\Application;
use App\Models\Intake;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\ApplicationReference;
use App\Support\CandidateEligibility;
use App\Support\Studentship;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApplicationStartService
{
    public function __construct(
        private InvoiceService $invoices,
        private AuditWriter $audit,
        private PremblyService $prembly,
        private ReturningApplicantPrefill $prefill,
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
            $this->ensureApplicantRole($user);
            if (in_array($existing->stage, ['started', 'awaiting_application_fee', 'fee_paid', 'form_in_progress'], true)) {
                $this->prembly->syncUserVerificationToApplication($user, $existing);
                $this->prefill->apply($user, $existing);
            }

            return $existing->fresh()->load(['applicationFeeInvoice', 'intake.term', 'steps', 'documents']);
        }

        $student = $user->relationLoaded('student') ? $user->student : $user->student()->first();
        abort_unless(
            Studentship::canApplyForAnotherProgramme($student),
            422,
            Studentship::INCOMPLETE_PROGRAMME_MESSAGE,
        );

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
        $this->ensureApplicantRole($user);
        $this->prembly->syncUserVerificationToApplication($user, $application);
        $this->prefill->apply($user, $application);
        $credentialsEmailed = $this->emailReturningCredentials($user, $student, $application);
        $this->audit->record('application.started', 'Application started ('.$intake->entry_mode.')', 'admissions', 'application', $application->id, null, $application);

        $fresh = $application->fresh(['applicationFeeInvoice', 'steps', 'documents', 'intake.term']);
        $fresh->setAttribute('credentials_emailed', $credentialsEmailed);

        return $fresh;
    }

    private function ensureApplicantRole(User $user): void
    {
        $applicantRole = Role::query()->where('slug', 'applicant')->where('is_active', true)->first();
        if ($applicantRole) {
            $user->roles()->syncWithoutDetaching([$applicantRole->id]);
        }
    }

    private function emailReturningCredentials(User $user, ?Student $student, Application $application): bool
    {
        if (! $student || ! Studentship::canApplyForAnotherProgramme($student) || ! $user->email) {
            return false;
        }

        $plainPassword = 'Aa1!'.Str::password(10, symbols: true);
        $user->forceFill([
            'password' => $plainPassword,
            'portal_credential_cipher' => null,
            'password_changed_at' => now(),
        ])->save();

        $student->loadMissing('application:id,application_number');

        try {
            Mail::to($user->email)->send(new ReturningApplicationCredentialsMail(
                $application->fresh(['user']),
                $student,
                $plainPassword,
                $student->application?->application_number,
            ));
            $application->update(['credentials_emailed_at' => now()]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('application.returning_credentials_email_failed', [
                'application_id' => $application->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
