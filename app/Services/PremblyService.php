<?php

namespace App\Services;

use App\Models\Application;
use App\Models\NinVerification;
use App\Models\Student;
use App\Models\User;
use App\Support\NinCipher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
        $hash = NinCipher::hash($nin);
        $query = NinVerification::query()->where('nin_hash', $hash);
        if ($exceptUserId) {
            $query->where('user_id', '!=', $exceptUserId);
        }
        if ($query->exists() || Student::query()->where('nin_hash', $hash)->when(
            $exceptUserId,
            fn ($builder) => $builder->where('user_id', '!=', $exceptUserId)
        )->exists()) {
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

    public function persistNinPhoto(User $user, string $nin, mixed $photo): ?string
    {
        if (! is_string($photo) || trim($photo) === '') {
            return null;
        }

        $photo = trim($photo);
        if (str_starts_with($photo, 'nin-photos/')) {
            return $photo;
        }

        $extension = 'jpg';
        $binary = null;

        if (preg_match('/^data:image\/(\w+);base64,(.+)$/i', $photo, $matches)) {
            $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
            $binary = base64_decode($matches[2], true);
        } else {
            $binary = base64_decode($photo, true);
        }

        if ($binary === false || $binary === '') {
            return null;
        }

        $path = "nin-photos/{$user->id}/{$nin}.{$extension}";
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    public function photoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('storage/'.ltrim($path, '/'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function identityPayload(?NinVerification $record): ?array
    {
        if (! $record?->verified_at) {
            return null;
        }

        $mapped = $record->mapped_fields ?? [];
        $photoPath = $mapped['photo_path'] ?? null;

        return [
            'nin' => $record->nin,
            'first_name' => $mapped['first_name'] ?? '',
            'middle_name' => $mapped['middle_name'] ?? '',
            'last_name' => $mapped['last_name'] ?? '',
            'date_of_birth' => $mapped['date_of_birth'] ?? null,
            'gender' => $mapped['gender'] ?? null,
            'photo_path' => $photoPath,
            'photo_url' => $this->photoUrl($photoPath),
        ];
    }

    public function ensurePhotoPersisted(User $user, NinVerification $record): NinVerification
    {
        $mapped = $record->mapped_fields ?? [];
        $photoPath = $mapped['photo_path'] ?? null;

        if (! $photoPath || ! str_starts_with($photoPath, 'nin-photos/')) {
            $photoPath = $this->persistNinPhoto($user, $record->nin, $mapped['photo_path'] ?? $mapped['photo'] ?? null);
            if ($photoPath) {
                $mapped['photo_path'] = $photoPath;
                unset($mapped['photo']);
                $record->update(['mapped_fields' => $mapped]);
            }
        }

        return $record->fresh();
    }

    public function verify(User $user, ?Application $application, string $nin): NinVerification
    {
        $nin = $this->normalizeNin($nin);
        $this->assertNinAvailable($nin, $user->id);

        $existing = NinVerification::query()
            ->where('user_id', $user->id)
            ->where('nin_hash', NinCipher::hash($nin))
            ->latest('id')
            ->first();
        if ($existing) {
            $existing = $this->ensurePhotoPersisted($user, $existing);
            if ($application) {
                $this->applyToApplication($application, $existing);
            }

            return $existing;
        }

        $mapped = $this->lookup($nin);
        $photoPath = $this->persistNinPhoto($user, $nin, $mapped['photo'] ?? null);
        if ($photoPath) {
            $mapped['photo_path'] = $photoPath;
        }
        unset($mapped['photo']);
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

        $this->audit->record('identity.nin.verify', 'NIN verified via Prembly', 'identity', 'nin_verification', $record->id, null, ['nin' => NinCipher::redact($nin)], null, $user);

        return $record;
    }

    public function syncUserVerificationToApplication(User $user, Application $application): void
    {
        $record = NinVerification::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->latest('id')
            ->first();
        if (! $record) {
            return;
        }

        $record = $this->ensurePhotoPersisted($user, $record);
        $this->applyToApplication($application, $record, ! $application->ninVerified());
    }

    private function applyToApplication(Application $application, NinVerification $record, bool $unlockForm = true): void
    {
        $user = $application->relationLoaded('user')
            ? $application->user
            : User::query()->find($application->user_id);

        if (! $user) {
            return;
        }

        $record = $this->ensurePhotoPersisted($user, $record);
        $mapped = $record->mapped_fields ?? [];
        $nin = $record->nin;

        $step = $application->steps()->firstOrNew(['step_key' => 'biodata']);
        $payload = $step->payload ?? [];
        if ($unlockForm || ($payload['nin_locked'] ?? false)) {
            $payload = array_merge($payload, [
                'nin' => $nin,
                'first_name' => $mapped['first_name'] ?? '',
                'middle_name' => $mapped['middle_name'] ?? '',
                'last_name' => $mapped['last_name'] ?? '',
                'date_of_birth' => $mapped['date_of_birth'] ?? null,
                'gender' => $mapped['gender'] ?? null,
                'photo_path' => $mapped['photo_path'] ?? null,
                'nin_locked' => true,
            ]);
            $step->payload = $payload;
            $step->status = 'saved';
            $step->save();
        }

        $this->attachPassportDocument($application, $mapped['photo_path'] ?? null);

        if (! $record->application_id) {
            $record->update(['application_id' => $application->id]);
        }

        if ($unlockForm && in_array($application->stage, ['fee_paid', 'form_in_progress'], true)) {
            $application->update([
                'stage' => 'form_in_progress',
                'current_step' => 'personal_details',
            ]);
        }
    }

    private function attachPassportDocument(Application $application, ?string $photoPath): void
    {
        if (! $photoPath || $application->documents()->where('doc_type', 'passport')->exists()) {
            return;
        }

        $doc = $application->documents()->create([
            'doc_type' => 'passport',
            'path' => $photoPath,
            'original_name' => 'nin-passport.jpg',
        ]);
        $documentsStep = $application->steps()->where('step_key', 'required_documents')->first();
        if ($documentsStep) {
            $documentsPayload = $documentsStep->payload ?? [];
            $documentsPayload['files'] = array_values(array_merge($documentsPayload['files'] ?? [], [
                $doc->only(['id', 'doc_type', 'path']),
            ]));
            $documentsPayload['passport_from_nin'] = true;
            $documentsStep->update([
                'payload' => $documentsPayload,
                'status' => $documentsStep->status === 'pending' ? 'saved' : $documentsStep->status,
            ]);
        }
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
            'photo' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFRUVFRUVFRUVFRUVFRUWFxUXFhUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAMgAyAMBEQACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAADBAECBQYAB//EADcQAAIBAwMCBAQEBgMAAAAAAAECAwAEERIhMQVBUWFxBhMiMoGRobHB0fAjQlLR4fEz/8QAGQEAAwEBAQAAAAAAAAAAAAAAAAECAwQF/8QAJREAAgICAgICAgIDAAAAAAAAAAECEQMhEjEEQRNRIjJhBXGB/9oADAMBAAIRAxEAPwD5VooooAKKKKACiiigAooooAKKKKACiiigD//Z',
            'raw' => ['demo' => true, 'nin' => $nin],
        ];
    }
}
