<?php

namespace App\Mail;

use App\Models\TranscriptRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TranscriptRequestRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TranscriptRequest $transcriptRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Transcript request update',
        );
    }

    public function content(): Content
    {
        $req = $this->transcriptRequest;

        return new Content(
            markdown: 'mail.transcript-request-rejected',
            with: [
                'studentName' => trim(($req->student?->first_name ?? '').' '.($req->student?->last_name ?? '')),
                'matric' => $req->student?->matric_number,
                'token' => $req->public_token,
                'reason' => $req->rejected_reason,
            ],
        );
    }
}
