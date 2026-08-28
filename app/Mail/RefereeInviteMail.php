<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\RefereeInvite;
use App\Support\PortalUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefereeInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public RefereeInvite $invite,
        public string $plainToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recommendation request — '.$this->application->application_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.referee-invite',
            with: [
                'refereeName' => $this->invite->name,
                'applicantName' => $this->application->user?->name,
                'applicationNumber' => $this->application->application_number,
                'programme' => $this->application->program?->name,
                'portalUrl' => PortalUrl::refereeInvite($this->plainToken),
            ],
        );
    }
}
