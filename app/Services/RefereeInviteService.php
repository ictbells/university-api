<?php

namespace App\Services;

use App\Mail\RefereeInviteMail;
use App\Models\Application;
use App\Models\RefereeInvite;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RefereeInviteService
{
    public function sync(Application $application, array $referees, bool $sendMail = true): void
    {
        $kept = [];
        foreach (array_values($referees) as $index => $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $invite = RefereeInvite::query()
                ->where('application_id', $application->id)
                ->where('email', $email)
                ->first();
            $payload = [
                'position' => $index + 1,
                'name' => (string) ($row['name'] ?? ''),
                'email' => $email,
                'institution' => $row['institution'] ?? null,
                'position_title' => $row['position'] ?? $row['position_title'] ?? null,
                'phone' => $row['phone'] ?? null,
            ];
            if (! $invite) {
                $token = $this->freshToken();
                $invite = RefereeInvite::query()->create($payload + [
                    'application_id' => $application->id,
                    'token_hash' => $this->hash($token),
                    'expires_at' => now()->addDays(30),
                    'status' => 'pending',
                ]);
                if ($sendMail) {
                    $this->send($application, $invite, $token);
                }
            } else {
                $invite->update($payload);
                if ($invite->status !== 'submitted' && $sendMail && $invite->isExpired()) {
                    $this->resend($application, $invite);
                }
            }
            $kept[] = $invite->id;
        }

        RefereeInvite::query()
            ->where('application_id', $application->id)
            ->whereNotIn('id', $kept ?: [0])
            ->where('status', '!=', 'submitted')
            ->delete();
    }

    public function resend(Application $application, RefereeInvite $invite): RefereeInvite
    {
        $token = $this->freshToken();
        $invite->update([
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addDays(30),
            'status' => $invite->status === 'submitted' ? 'submitted' : 'pending',
        ]);
        $this->send($application, $invite, $token);

        return $invite->fresh();
    }

    public function findByPlainToken(string $token): ?RefereeInvite
    {
        $invite = RefereeInvite::query()->where('token_hash', $this->hash($token))->first();
        if (! $invite) {
            return null;
        }
        if ($invite->isExpired()) {
            $invite->update(['status' => 'expired']);

            return $invite->fresh();
        }

        return $invite;
    }

    public function publicPayload(RefereeInvite $invite): array
    {
        $invite->loadMissing('application.user', 'application.program');

        return [
            'status' => $invite->status,
            'expired' => $invite->isExpired(),
            'referee_name' => $invite->name,
            'applicant_name' => $invite->application?->user?->name,
            'programme' => $invite->application?->program?->name,
            'application_number' => $invite->application?->application_number,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'submitted_at' => $invite->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statusFor(Application $application): array
    {
        return $application->refereeInvites()
            ->orderBy('position')
            ->get()
            ->map(fn (RefereeInvite $invite) => [
                'id' => $invite->id,
                'position' => $invite->position,
                'name' => $invite->name,
                'email' => $invite->email,
                'institution' => $invite->institution,
                'status' => $invite->isExpired() ? 'expired' : $invite->status,
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'submitted_at' => $invite->submitted_at?->toIso8601String(),
            ])
            ->all();
    }

    private function send(Application $application, RefereeInvite $invite, string $token): void
    {
        $application->loadMissing('user', 'program');
        Mail::to($invite->email)->send(new RefereeInviteMail($application, $invite, $token));
    }

    private function freshToken(): string
    {
        return Str::random(48);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
