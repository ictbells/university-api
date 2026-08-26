<?php

namespace App\Mail;

use App\Models\TranscriptRequest;
use App\Support\TranscriptRequestSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TranscriptRequestReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TranscriptRequest $transcriptRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your official transcript request is ready',
        );
    }

    public function content(): Content
    {
        $req = $this->transcriptRequest;
        $settings = TranscriptRequestSettings::all();
        $base = rtrim((string) config('app.student_url'), '/');

        return new Content(
            markdown: 'mail.transcript-request-ready',
            with: [
                'studentName' => trim(($req->student?->first_name ?? '').' '.($req->student?->last_name ?? '')),
                'matric' => $req->student?->matric_number,
                'token' => $req->public_token,
                'deliveryMode' => $req->delivery_mode,
                'collectInstructions' => $settings['transcript_collect_instructions'],
                'downloadUrl' => $req->isDownloadable()
                    ? $base.'/transcript-request?token='.$req->public_token
                    : null,
                'portalUrl' => $base.'/transcript-request?token='.$req->public_token,
            ],
        );
    }
}
