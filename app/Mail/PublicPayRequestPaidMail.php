<?php

namespace App\Mail;

use App\Models\PublicPayRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublicPayRequestPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PublicPayRequest $publicPayRequest) {}

    public function envelope(): Envelope
    {
        $name = $this->publicPayRequest->offer?->name ?: 'request';

        return new Envelope(
            subject: 'Payment received — '.$name,
        );
    }

    public function content(): Content
    {
        $req = $this->publicPayRequest;

        return new Content(
            markdown: 'mail.public-pay-request-paid',
            with: [
                'studentName' => trim(($req->student?->first_name ?? '').' '.($req->student?->last_name ?? '')),
                'matric' => $req->student?->matric_number,
                'offerName' => $req->offer?->name,
                'token' => $req->public_token,
                'portalUrl' => rtrim((string) config('app.student_url'), '/').'/request-pay?token='.$req->public_token,
            ],
        );
    }
}
