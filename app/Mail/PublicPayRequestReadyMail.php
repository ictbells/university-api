<?php

namespace App\Mail;

use App\Models\PublicPayRequest;
use App\Support\PublicPaySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublicPayRequestReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PublicPayRequest $publicPayRequest) {}

    public function envelope(): Envelope
    {
        $name = $this->publicPayRequest->offer?->name ?: 'request';

        return new Envelope(
            subject: 'Your request is ready — '.$name,
        );
    }

    public function content(): Content
    {
        $req = $this->publicPayRequest;
        $settings = PublicPaySettings::all();
        $base = rtrim((string) config('app.student_url'), '/');

        return new Content(
            markdown: 'mail.public-pay-request-ready',
            with: [
                'studentName' => trim(($req->student?->first_name ?? '').' '.($req->student?->last_name ?? '')),
                'matric' => $req->student?->matric_number,
                'offerName' => $req->offer?->name,
                'token' => $req->public_token,
                'deliveryMode' => $req->delivery_mode,
                'collectInstructions' => $settings['public_pay_collect_instructions'],
                'downloadUrl' => $req->isDownloadable()
                    ? $base.'/request-pay?token='.$req->public_token
                    : null,
                'portalUrl' => $base.'/request-pay?token='.$req->public_token,
            ],
        );
    }
}
