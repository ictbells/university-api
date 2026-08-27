<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        $university = (string) Setting::getValue('university_name', 'Bells University of Technology');

        return new Envelope(
            subject: 'Your '.$university.' staff portal account',
        );
    }

    public function content(): Content
    {
        $portalUrl = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            markdown: 'mail.staff-credentials',
            with: [
                'staffName' => $this->user->name ?: 'Colleague',
                'email' => $this->user->email,
                'plainPassword' => $this->plainPassword,
                'portalUrl' => $portalUrl,
            ],
        );
    }
}
