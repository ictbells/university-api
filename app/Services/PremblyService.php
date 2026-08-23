<?php

namespace App\Services;

use App\Models\Application;
use App\Models\NinVerification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PremblyService
{
    public function __construct(private AuditWriter $audit) {}

    public function normalizeNin(string $nin): string
    {
        $nin = preg_replace('/\D/', '', $nin);
        if (strlen($nin) !== 11) {
            throw ValidationException::withMessages(['nin' => 'NIN must be 11 digits.']);
        }

        return $nin;
    }

    public function lookupIdentity(string $nin): array
    {
        return $this->lookup($this->normalizeNin($nin));
    }

    public function assertNinAvailable(string $nin, ?int $exceptUserId = null): void
    {
        $nin = $this->normalizeNin($nin);
        $query = NinVerification::query()->where('nin', $nin);
        if ($exceptUserId) {
            $query->where('user_id', '!=', $exceptUserId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['nin' => 'This NIN is already linked to an account.']);
        }
    }

    public function displayName(array $mapped): string
    {
        return trim(implode(' ', array_filter([
            $mapped['first_name'] ?? '',
            $mapped['middle_name'] ?? '',
            $mapped['last_name'] ?? '',
        ])));
    }

    public function verify(User $user, ?Application $application, string $nin): NinVerification
    {
        $nin = $this->normalizeNin($nin);
        $this->assertNinAvailable($nin, $user->id);

        $existing = NinVerification::query()
            ->where('user_id', $user->id)
            ->where('nin', $nin)
            ->latest('id')
            ->first();
        if ($existing) {
            if ($application) {
                $this->applyToApplication($application, $existing);
            }

            return $existing;
        }

        $mapped = $this->lookup($nin);
        $record = NinVerification::query()->create([
            'user_id' => $user->id,
            'application_id' => $application?->id,
            'nin' => $nin,
            'prembly_reference' => $mapped['reference'] ?? null,
            'mapped_fields' => $mapped,
            'raw_snapshot' => $mapped['raw'] ?? $mapped,
            'verified_at' => now(),
        ]);

        if ($application) {
            $this->applyToApplication($application, $record);
        }

        $this->audit->record('identity.nin.verify', 'NIN verified via Prembly', 'identity', 'nin_verification', $record->id, null, ['nin' => $nin], null, $user);

        return $record;
    }

    public function syncUserVerificationToApplication(User $user, Application $application): void
    {
        if ($application->ninVerified()) {
            return;
        }

        $record = NinVerification::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->latest('id')
            ->first();
        if (! $record) {
            return;
        }

        $this->applyToApplication($application, $record);
    }

    private function applyToApplication(Application $application, NinVerification $record): void
    {
        $mapped = $record->mapped_fields ?? [];
        $nin = $record->nin;

        $step = $application->steps()->firstOrNew(['step_key' => 'biodata']);
        $payload = $step->payload ?? [];
        $payload = array_merge($payload, [
            'nin' => $nin,
            'first_name' => $mapped['first_name'] ?? '',
            'middle_name' => $mapped['middle_name'] ?? '',
            'last_name' => $mapped['last_name'] ?? '',
            'date_of_birth' => $mapped['date_of_birth'] ?? null,
            'gender' => $mapped['gender'] ?? null,
            'photo_path' => $mapped['photo'] ?? null,
            'nin_locked' => true,
        ]);
        $step->payload = $payload;
        $step->status = 'saved';
        $step->save();

        if (! $record->application_id) {
            $record->update(['application_id' => $application->id]);
        }

        $application->update([
            'stage' => 'form_in_progress',
            'current_step' => 'biodata',
        ]);
    }

    private function lookup(string $nin): array
    {
        $key = config('services.prembly.key');
        $appId = config('services.prembly.app_id');
        $base = rtrim(config('services.prembly.base', 'https://api.prembly.com'), '/');

        if ($key && $appId) {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'app-id' => $appId,
            ])->post($base.'/identitypass/verification/vnin', [
                'number' => $nin,
            ]);
            if (! $response->successful() && ! $response->json('status')) {
                throw new RuntimeException($response->json('detail') ?: $response->json('message') ?: 'Prembly NIN verification failed.');
            }
            $data = $response->json('data.nin_data') ?? $response->json('data') ?? [];

            return [
                'reference' => $response->json('response_code') ?: $response->json('id'),
                'first_name' => $data['firstname'] ?? $data['firstName'] ?? $data['first_name'] ?? '',
                'middle_name' => $data['middlename'] ?? $data['middleName'] ?? $data['middle_name'] ?? '',
                'last_name' => $data['surname'] ?? $data['lastname'] ?? $data['last_name'] ?? '',
                'date_of_birth' => $data['birthdate'] ?? $data['dateOfBirth'] ?? $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'photo' => $data['photo'] ?? $data['picture'] ?? null,
                'raw' => $data,
            ];
        }

        return [
            'reference' => 'DEMO-'.$nin,
            'first_name' => 'Adaeze',
            'middle_name' => 'Chioma',
            'last_name' => 'Okoye',
            'date_of_birth' => '2004-03-18',
            'gender' => 'Female',
            'photo' => null,
            'raw' => ['demo' => true, 'nin' => $nin],
        ];
    }
}
