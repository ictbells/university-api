<?php

namespace App\Services;

use App\Mail\ApplicationCredentialsMail;
use App\Models\Application;
use App\Models\Invoice;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ApplicationAdmissionService
{
    public function __construct(
        private StudentCreationService $students,
        private Notifier $notifier,
    ) {}

    public function handleInvoicePaid(Invoice $invoice): void
    {
        if ($invoice->category === 'application_fee' && $invoice->application_id) {
            $this->handleApplicationFeePaid($invoice);
        }

        if ($invoice->category === 'acceptance_fee' && $invoice->application_id) {
            $this->handleAcceptanceFeePaid($invoice);
        }
    }

    private function handleApplicationFeePaid(Invoice $invoice): void
    {
        $app = $invoice->application()->first() ?? Application::query()->find($invoice->application_id);
        if (! $app || ! in_array($app->stage, ['started', 'awaiting_application_fee'], true)) {
            return;
        }

        $app->update(['stage' => 'fee_paid', 'current_step' => 'biodata']);
        $this->notifier->send(
            $app->user,
            'application_fee',
            'Application fee received',
            'You can now complete your application form.',
            'admissions',
            $app->id,
        );
        $this->sendCredentialsEmail($app->fresh(['user']));
    }

    private function handleAcceptanceFeePaid(Invoice $invoice): void
    {
        $app = Application::query()->find($invoice->application_id);
        if (! $app || $app->student_id) {
            return;
        }

        $app->update(['stage' => 'acceptance_paid']);
        $this->students->createFromApplication($app->fresh());
    }

    public function sendCredentialsEmail(Application $application): void
    {
        if ($application->credentials_emailed_at || ! $application->application_number) {
            return;
        }

        $user = $application->user;
        if (! $user?->email) {
            return;
        }

        $loginId = $user->jamb_registration ?: $application->application_number;
        $plainPassword = $this->resolveRegistrationPassword($user);

        try {
            Mail::to($user->email)->send(new ApplicationCredentialsMail($application, $loginId, $plainPassword));
            $application->update(['credentials_emailed_at' => now()]);
            if ($plainPassword !== null) {
                $user->update(['portal_credential_cipher' => null]);
            }
        } catch (\Throwable $exception) {
            Log::warning('application.credentials_email_failed', [
                'application_id' => $application->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveRegistrationPassword($user): ?string
    {
        if (! $user->portal_credential_cipher) {
            return null;
        }

        try {
            return Crypt::decryptString($user->portal_credential_cipher);
        } catch (\Throwable) {
            return null;
        }
    }
}
