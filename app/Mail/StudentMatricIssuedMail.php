<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentMatricIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Student $student,
        public string $matricNumber,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isPostgraduate()
                ? 'Your postgraduate matric number — Bells University student portal'
                : 'Your matric number — Bells University student portal',
        );
    }

    public function content(): Content
    {
        $name = trim((string) ($this->student->user?->name
            ?: trim(($this->student->first_name ?? '').' '.($this->student->last_name ?? ''))));

        return new Content(
            markdown: 'mail.student-matric-issued',
            with: [
                'studentName' => $name !== '' ? $name : 'Student',
                'matricNumber' => $this->matricNumber,
                'applicationNumber' => $this->student->application?->application_number,
                'portalUrl' => rtrim((string) config('app.student_url'), '/'),
                'loginNote' => $this->isPostgraduate()
                    ? 'Application number will no longer work.'
                    : 'Application number and JAMB will no longer work.',
            ],
        );
    }

    public function isPostgraduate(): bool
    {
        $mode = strtolower(trim((string) ($this->student->application?->entry_mode ?? '')));
        $level = strtolower(trim((string) ($this->student->study_level ?? '')));

        return $mode === 'pg' || $level === 'postgraduate';
    }
}
