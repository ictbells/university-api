<?php

namespace App\Mail;

use App\Models\Application;
use App\Support\AdmissionEntryRules;
use App\Support\StudentPortalAuth;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public string $loginId,
        public ?string $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Bells University student portal account',
        );
    }

    public function content(): Content
    {
        [$signInLabel, $signInValue] = $this->signInIdentity();

        return new Content(
            markdown: 'mail.application-credentials',
            with: [
                'signInLabel' => $signInLabel,
                'signInValue' => $signInValue,
                'plainPassword' => $this->plainPassword,
                'portalUrl' => rtrim((string) config('app.student_url'), '/'),
                'applicantName' => $this->application->user?->name,
            ],
        );
    }

    /**
     * UTME/DE sign in with JAMB. Other applicants use application number.
     * Matriculated imports keep matric as the sign-in id.
     *
     * @return array{0: string, 1: string}
     */
    public function signInIdentity(): array
    {
        if (StudentPortalAuth::looksLikeMatric($this->loginId)
            && ! StudentPortalAuth::looksLikeApplicationNumber($this->loginId)) {
            return ['Matric number', $this->loginId];
        }

        $jamb = trim((string) ($this->application->jamb_registration ?: $this->application->user?->jamb_registration));
        if (AdmissionEntryRules::requiresJambRegistration((string) $this->application->entry_mode) && $jamb !== '') {
            return ['JAMB number', $jamb];
        }

        return ['Application number', (string) ($this->application->application_number ?: $this->loginId)];
    }
}
