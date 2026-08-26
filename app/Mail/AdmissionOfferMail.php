<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdmissionOfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Offer of admission — Bells University of Technology',
        );
    }

    public function content(): Content
    {
        $app = $this->application;
        $fee = $app->acceptanceFeeInvoice;
        $amount = ($fee && in_array($fee->status, ['unpaid', 'partial', 'paid'], true))
            ? (float) $fee->amount
            : 0.0;
        $session = (string) ($app->intake?->term?->session_label
            ?: $app->intake?->term?->session?->label
            ?: '');

        return new Content(
            markdown: 'mail.admission-offer',
            with: [
                'applicantName' => $app->user?->name ?: 'Applicant',
                'programme' => $app->program?->name ?: 'your programme',
                'session' => $session,
                'offerReference' => $app->offer_reference,
                'applicationNumber' => $app->application_number,
                'acceptanceAmount' => $amount > 0 ? number_format($amount, 0) : null,
                'portalUrl' => rtrim((string) config('app.student_url'), '/'),
            ],
        );
    }
}
