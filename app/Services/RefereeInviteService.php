<?php

namespace App\Services;

use App\Mail\RefereeInviteMail;
use App\Models\Application;
use App\Models\RefereeInvite;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /**
     * @param  array{email?: string, name?: string}  $attrs
     */
    public function updateContact(Application $application, RefereeInvite $invite, array $attrs): RefereeInvite
    {
        $email = isset($attrs['email']) ? strtolower(trim((string) $attrs['email'])) : null;
        $name = isset($attrs['name']) ? trim((string) $attrs['name']) : null;
        $oldEmail = (string) $invite->email;
        $payload = [];

        if ($email !== null && $email !== '') {
            $duplicate = RefereeInvite::query()
                ->where('application_id', $application->id)
                ->where('email', $email)
                ->where('id', '!=', $invite->id)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'email' => 'Another referee on this application already uses this email.',
                ]);
            }
            $payload['email'] = $email;
        }
        if ($name !== null && $name !== '') {
            $payload['name'] = $name;
        }
        if ($payload === []) {
            return $invite;
        }

        $invite->update($payload);
        $invite = $invite->fresh() ?? $invite;
        $this->syncStepPayload($application, $oldEmail, $invite);

        return $invite;
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

    private function syncStepPayload(Application $application, string $oldEmail, RefereeInvite $invite): void
    {
        $step = $application->steps()->where('step_key', 'pg_referees')->first();
        if (! $step) {
            return;
        }

        $payload = is_array($step->payload) ? $step->payload : [];
        $referees = array_values($payload['referees'] ?? []);
        $old = strtolower(trim($oldEmail));
        $current = strtolower(trim((string) $invite->email));
        $updated = false;

        foreach ($referees as $index => $row) {
            $rowEmail = strtolower(trim((string) ($row['email'] ?? '')));
            $matched = $rowEmail === $old || $rowEmail === $current
                || ((int) $invite->position === $index + 1 && ($rowEmail === $old || $rowEmail === ''));
            if (! $matched) {
                continue;
            }
            $referees[$index]['email'] = $invite->email;
            if ($invite->name) {
                $referees[$index]['name'] = $invite->name;
            }
            $updated = true;
            break;
        }

        if (! $updated) {
            $index = ((int) $invite->position) - 1;
            if ($index >= 0 && isset($referees[$index])) {
                $referees[$index]['email'] = $invite->email;
                if ($invite->name) {
                    $referees[$index]['name'] = $invite->name;
                }
                $updated = true;
            }
        }

        if ($updated) {
            $step->update(['payload' => array_merge($payload, ['referees' => $referees])]);
        }
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
