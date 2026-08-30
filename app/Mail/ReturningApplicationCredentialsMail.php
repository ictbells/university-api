<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturningApplicationCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public Student $student,
        public string $plainPassword,
        public ?string $previousApplicationNumber = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Continue your Bells University application',
        );
    }

    public function content(): Content
    {
        $matric = trim((string) ($this->student->matric_number ?: $this->student->student_number));
        $previous = trim((string) ($this->previousApplicationNumber ?: $this->student->application?->application_number));
        $signInValue = $matric !== '' ? $matric : ($previous !== '' ? $previous : (string) $this->application->application_number);
        $signInLabel = $matric !== ''
            ? 'Matric number'
            : ($previous !== '' ? 'Previous application number' : 'Application number');

        return new Content(
            markdown: 'mail.returning-application-credentials',
            with: [
                'applicantName' => $this->application->user?->name ?: $this->student->first_name,
                'signInLabel' => $signInLabel,
                'signInValue' => $signInValue,
                'matricNumber' => $matric !== '' ? $matric : null,
                'previousApplicationNumber' => $previous !== '' ? $previous : null,
                'newApplicationNumber' => $this->application->application_number,
                'plainPassword' => $this->plainPassword,
                'portalUrl' => rtrim((string) config('app.student_url'), '/'),
            ],
        );
    }
}
