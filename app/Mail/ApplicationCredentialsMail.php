<?php

namespace App\Mail;

use App\Models\Application;
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
            subject: 'Your Bells University application credentials',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.application-credentials',
            with: [
                'applicationNumber' => $this->application->application_number,
                'loginId' => $this->loginId,
                'plainPassword' => $this->plainPassword,
                'portalUrl' => rtrim((string) config('app.student_url'), '/'),
                'applicantName' => $this->application->user?->name,
            ],
        );
    }
}
