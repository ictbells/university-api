<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffLoginNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $signedInAt,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}

    public function envelope(): Envelope
    {
        $university = (string) Setting::getValue('university_name', 'Bells University of Technology');

        return new Envelope(
            subject: 'Staff portal sign-in — '.$university,
        );
    }

    public function content(): Content
    {
        $portalUrl = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            markdown: 'mail.staff-login-notification',
            with: [
                'staffName' => $this->user->name ?: 'Colleague',
                'signedInAt' => $this->signedInAt,
                'ipAddress' => $this->ipAddress ?: 'Unknown',
                'device' => $this->userAgent ?: 'Unknown device',
                'portalUrl' => $portalUrl,
                'resetUrl' => $portalUrl.'/forgot-password',
            ],
        );
    }
}
