<?php

namespace App\Services;

use App\Models\Application;
use App\Models\NinVerification;
use App\Models\Student;
use App\Models\User;
use App\Support\NinCipher;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PremblyService
{
    public const EXISTING_ACCOUNT_MESSAGE = 'This NIN is already linked to an account. Sign in with your matric number, application number, or JAMB. Use Forgot password if needed.';

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

    public function isConfigured(): bool
    {
        return $this->credentialsConfigured();
    }

    public function isLiveMapped(?array $mapped): bool
    {
        return $mapped !== null && ($mapped['raw']['demo'] ?? false) !== true;
    }

    public function ninIsLinked(string $nin, ?int $exceptUserId = null): bool
    {
        $nin = $this->normalizeNin($nin);
        $hash = NinCipher::hash($nin);
        $query = NinVerification::query()->where('nin_hash', $hash);
        if ($exceptUserId) {
            $query->where('user_id', '!=', $exceptUserId);
        }
        if ($query->exists()) {
            return true;
        }

        return Student::query()->where('nin_hash', $hash)->when(
            $exceptUserId,
            fn ($builder) => $builder->where('user_id', '!=', $exceptUserId)
        )->exists();
    }

    public function assertNinAvailable(string $nin, ?int $exceptUserId = null): void
    {
        if ($this->ninIsLinked($nin, $exceptUserId)) {
            throw ValidationException::withMessages(['nin' => self::EXISTING_ACCOUNT_MESSAGE]);
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
        \App\Support\AppStorage::disk()->put($path, $binary);

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

        return \App\Support\AppStorage::url(ltrim($path, '/'));
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
            'phone' => $this->phoneFromMapped($mapped),
            'address' => $this->addressFromMapped($mapped),
            'photo_path' => $photoPath,
            'photo_url' => $this->photoUrl($photoPath),
            'live' => $this->isLiveRecord($record),
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

    public function verify(User $user, ?Application $application, string $nin, ?array $mapped = null): NinVerification
    {
        $nin = $this->normalizeNin($nin);
        $this->assertNinAvailable($nin, $user->id);

        $existing = NinVerification::query()
            ->where('user_id', $user->id)
            ->where('nin_hash', NinCipher::hash($nin))
            ->latest('id')
            ->first();
        if ($existing && $this->isLiveRecord($existing) && $mapped === null) {
            $existing = $this->ensurePhotoPersisted($user, $existing);
            if ($application) {
                $this->applyToApplication($application, $existing);
            }

            return $existing;
        }

        $mapped ??= $this->lookup($nin);
        $photoPath = $this->persistNinPhoto($user, $nin, $mapped['photo'] ?? null);
        if ($photoPath) {
            $mapped['photo_path'] = $photoPath;
        }
        unset($mapped['photo']);
        $payload = [
            'user_id' => $user->id,
            'application_id' => $application?->id ?? $existing?->application_id,
            'nin' => $nin,
            'prembly_reference' => $mapped['reference'] ?? null,
            'mapped_fields' => $mapped,
            'raw_snapshot' => $mapped['raw'] ?? $mapped,
            'verified_at' => now(),
        ];
        if ($existing) {
            $existing->update($payload);
            $record = $existing->fresh();
        } else {
            $record = NinVerification::query()->create($payload);
        }

        if ($application) {
            $this->applyToApplication($application, $record);
        }

        $this->audit->record('identity.nin.verify', 'NIN verified via Prembly', 'identity', 'nin_verification', $record->id, null, ['nin' => NinCipher::redact($nin), 'live' => $this->isLiveMapped($mapped)], null, $user);

        return $record;
    }

    public function resyncFromNin(Application $application, User $actor): NinVerification
    {
        $application->loadMissing(['user.student', 'student', 'steps']);
        $user = $application->user;
        if (! $user) {
            throw ValidationException::withMessages(['nin' => 'This application has no applicant account.']);
        }
        $nin = $this->storedNin($application, $user);
        if (! $nin) {
            throw ValidationException::withMessages(['nin' => 'This file has no NIN to resync.']);
        }
        $this->assertNinAvailable($nin, $user->id);
        try {
            $mapped = $this->lookup($nin);
        } catch (RuntimeException $e) {
            $this->audit->record(
                'identity.nin.resync_failed',
                'Staff NIN resync failed',
                'identity',
                'application',
                $application->id,
                null,
                ['nin' => NinCipher::redact($nin), 'error' => $e->getMessage()],
                null,
                $actor,
            );
            throw ValidationException::withMessages(['nin' => $e->getMessage()]);
        }

        $record = $this->verify($user, $application, $nin, $mapped);
        $this->applyIdentityToStudent($user->student ?? $application->student, $record);
        $name = $this->displayName($record->mapped_fields ?? []);
        if ($name !== '') {
            $user->update(['name' => $name]);
        }
        $this->audit->record(
            'identity.nin.resync',
            'Staff resynced NIN biodata from Prembly',
            'identity',
            'nin_verification',
            $record->id,
            null,
            ['nin' => NinCipher::redact($nin), 'live' => $this->isLiveMapped($record->mapped_fields)],
            null,
            $actor,
        );

        return $record;
    }

    private function storedNin(Application $application, User $user): ?string
    {
        $biodata = $application->steps->firstWhere('step_key', 'biodata')?->payload
            ?? $application->steps()->where('step_key', 'biodata')->value('payload');
        $fromStep = is_array($biodata) ? trim((string) ($biodata['nin'] ?? '')) : '';
        if ($fromStep !== '') {
            return $this->normalizeNin($fromStep);
        }
        $fromStudent = trim((string) ($user->student?->nin ?: $application->student?->nin ?: ''));
        if ($fromStudent !== '') {
            return $this->normalizeNin($fromStudent);
        }
        $record = NinVerification::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->latest('id')
            ->first();
        $fromRecord = trim((string) ($record?->nin ?? ''));

        return $fromRecord !== '' ? $this->normalizeNin($fromRecord) : null;
    }

    private function applyIdentityToStudent(?Student $student, NinVerification $record): void
    {
        if (! $student) {
            return;
        }
        $mapped = $record->mapped_fields ?? [];
        $student->update([
            'nin' => $record->nin,
            'first_name' => $mapped['first_name'] ?? $student->first_name,
            'middle_name' => $mapped['middle_name'] ?? $student->middle_name,
            'last_name' => $mapped['last_name'] ?? $student->last_name,
            'date_of_birth' => $mapped['date_of_birth'] ?? $student->date_of_birth,
            'gender' => $mapped['gender'] ?? $student->gender,
            'photo_path' => $mapped['photo_path'] ?? $student->photo_path,
            'nin_locked' => true,
        ]);
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

        $phone = $this->phoneFromMapped($mapped);
        $address = $this->addressFromMapped($mapped);
        if ($phone !== '' && blank($user->phone)) {
            $user->update(['phone' => $phone]);
        }

        $contact = $application->steps()->firstOrNew(['step_key' => 'application_form']);
        $contactPayload = is_array($contact->payload) ? $contact->payload : [];
        if ($phone !== '' && blank($contactPayload['phone'] ?? null)) {
            $contactPayload['phone'] = $phone;
        }
        if ($address !== '' && blank($contactPayload['address'] ?? null)) {
            $contactPayload['address'] = $address;
        }
        if ($contactPayload !== (is_array($contact->payload) ? $contact->payload : [])) {
            $contact->payload = $contactPayload;
            if ($contact->status === 'pending' || ! $contact->exists) {
                $contact->status = $contact->status ?: 'pending';
            }
            $contact->save();
        }

        $this->attachPassportDocument($application, $mapped['photo_path'] ?? null);
        $user->loadMissing('student');
        $application->loadMissing('student');
        $this->applyIdentityToStudent($user->student ?? $application->student, $record);

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
        if (! $photoPath) {
            return;
        }

        $existing = $application->documents()->where('doc_type', 'passport')->first();
        if ($existing) {
            if ($existing->path !== $photoPath) {
                $existing->update([
                    'path' => $photoPath,
                    'original_name' => 'nin-passport.jpg',
                ]);
            }

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
        if (! $this->credentialsConfigured()) {
            if (! $this->demoAllowed()) {
                throw new RuntimeException('Live NIN verification is not configured. Set PREMBLY_API_KEY and PREMBLY_APP_ID on the API server.');
            }

            return $this->demoIdentity($nin);
        }

        $base = rtrim((string) config('services.prembly.base', 'https://api.prembly.com'), '/');
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.prembly.key'),
            'app-id' => (string) config('services.prembly.app_id'),
        ])->acceptJson()->asJson()->timeout(45)->post($base.'/identitypass/verification/vnin', [
            'number_nin' => $nin,
            'number' => $nin,
        ]);

        $status = $response->json('status');
        $ok = $response->successful() && $status !== false && $status !== 'false' && $status !== 0;
        if (! $ok) {
            $message = $response->json('detail')
                ?: $response->json('message')
                ?: $response->json('response_message')
                ?: 'Prembly NIN verification failed.';
            throw new RuntimeException(is_string($message) ? $message : 'Prembly NIN verification failed.');
        }

        $data = $this->ninBiodataFromResponse($response);
        if ($data === []) {
            throw new RuntimeException('Prembly returned no NIN biodata.');
        }

        $contact = $this->contactFromNinData($data);
        $json = $response->json();
        if ($contact['phone'] === '' && is_array($json)) {
            $contact['phone'] = $this->contactFromNinData($json)['phone'];
        }
        if ($contact['address'] === '' && is_array($json)) {
            $contact['address'] = $this->contactFromNinData($json)['address'];
        }

        return [
            'reference' => $response->json('verification.reference')
                ?: $response->json('response_code')
                ?: $response->json('id'),
            'first_name' => $data['firstname'] ?? $data['firstName'] ?? $data['first_name'] ?? '',
            'middle_name' => $data['middlename'] ?? $data['middleName'] ?? $data['middle_name'] ?? '',
            'last_name' => $data['surname'] ?? $data['lastname'] ?? $data['last_name'] ?? '',
            'date_of_birth' => $this->normalizeDate($data['birthdate'] ?? $data['dateOfBirth'] ?? $data['date_of_birth'] ?? null),
            'gender' => $this->normalizeGender($data['gender'] ?? null),
            'phone' => $contact['phone'],
            'address' => $contact['address'],
            'photo' => $data['photo'] ?? $data['picture'] ?? $data['base64Image'] ?? null,
            'raw' => is_array($json) ? $json : $data,
        ];
    }

    /**
     * Prembly returns biodata on `nin_data`, `data.nin_data`, and/or `data`.
     * Merge them so an empty telephoneno on one copy does not hide a filled number on another.
     *
     * @return array<string, mixed>
     */
    private function ninBiodataFromResponse(\Illuminate\Http\Client\Response $response): array
    {
        $parts = [];
        foreach (['nin_data', 'data.nin_data', 'data'] as $path) {
            $chunk = $response->json($path);
            if (! is_array($chunk) || $chunk === []) {
                continue;
            }
            if ($path === 'data' && isset($chunk['nin_data']) && is_array($chunk['nin_data'])) {
                unset($chunk['nin_data']);
                if ($chunk === []) {
                    continue;
                }
            }
            $parts[] = $chunk;
        }

        $merged = [];
        foreach ($parts as $part) {
            foreach ($part as $key => $value) {
                if ($this->isBlankNinValue($merged[$key] ?? null) && ! $this->isBlankNinValue($value)) {
                    $merged[$key] = $value;
                } elseif (! array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    private function isBlankNinValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function credentialsConfigured(): bool
    {
        return filled(trim((string) config('services.prembly.key')))
            && filled(trim((string) config('services.prembly.app_id')));
    }

    private function demoAllowed(): bool
    {
        $flag = config('services.prembly.allow_demo');
        if ($flag === null || $flag === '') {
            return ! app()->isProduction();
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function isLiveRecord(NinVerification $record): bool
    {
        $raw = $record->raw_snapshot ?? [];
        if (($raw['demo'] ?? false) === true) {
            return false;
        }
        $ref = (string) ($record->prembly_reference ?? '');

        return $ref === '' || ! str_starts_with($ref, 'DEMO-');
    }

    private function demoIdentity(string $nin): array
    {
        return [
            'reference' => 'DEMO-'.$nin,
            'first_name' => 'Adaeze',
            'middle_name' => 'Chioma',
            'last_name' => 'Okoye',
            'date_of_birth' => '2004-03-18',
            'gender' => 'Female',
            'phone' => '08030000000',
            'address' => 'KM 8, Idiroko Road, Benja Village, Ota, Ogun State',
            'photo' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFRUVFRUVFRUVFRUVFRUWFxUXFhUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAMgAyAMBEQACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAADBAECBQYAB//EADcQAAIBAwMCBAQEBgMAAAAAAAECAwAEERIhMQVBUWFxBhMiMoGRobHB0fAjQlLR4fEz/8QAGQEAAwEBAQAAAAAAAAAAAAAAAAECAwQF/8QAJREAAgICAgICAgIDAAAAAAAAAAECEQMhEjEEQRNRIjJhBXGB/9oADAMBAAIRAxEAPwD5VooooAKKKKACiiigAooooAKKKKACiiigD//Z',
            'raw' => ['demo' => true, 'nin' => $nin],
        ];
    }

    private function normalizeGender(mixed $gender): ?string
    {
        $value = strtolower(trim((string) $gender));
        if (in_array($value, ['m', 'male'], true)) {
            return 'Male';
        }
        if (in_array($value, ['f', 'female'], true)) {
            return 'Female';
        }

        return $gender ? (string) $gender : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'j M Y', 'd M Y', 'j F Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d');
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    public function mappedPhone(array $mapped): string
    {
        return $this->phoneFromMapped($mapped);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    public function mappedAddress(array $mapped): string
    {
        return $this->addressFromMapped($mapped);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function phoneFromMapped(array $mapped): string
    {
        $direct = trim((string) ($mapped['phone'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        $raw = $mapped['raw'] ?? null;

        return $this->contactFromNinData(is_array($raw) ? $raw : $mapped)['phone'];
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function addressFromMapped(array $mapped): string
    {
        $direct = trim((string) ($mapped['address'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        $raw = $mapped['raw'] ?? null;

        return $this->contactFromNinData(is_array($raw) ? $raw : $mapped)['address'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{phone: string, address: string}
     */
    private function contactFromNinData(array $data): array
    {
        $phone = $this->firstFilled($data, [
            'phone', 'telephoneno', 'telephone_no', 'telephone', 'telephoneNo', 'telephone_number',
            'mobile', 'mobile_number', 'mobileNumber', 'mobileNo', 'phone_number', 'phoneNumber',
            'phoneNo', 'gsm', 'tel', 'msisdn', 'nin_phone',
        ]);
        $address = $this->firstFilled($data, [
            'residence_address', 'residence_AdressLine1', 'residential_address', 'address',
            'residenceAddress', 'house_address', 'permanent_address', 'home_address',
        ]);
        if ($address === '') {
            $address = trim(implode(', ', array_filter([
                $this->firstFilled($data, ['residence_town', 'residence_Town', 'town', 'lga_of_residence']),
                $this->firstFilled($data, ['residence_lga', 'lga']),
                $this->firstFilled($data, ['residence_state', 'state', 'state_of_residence']),
            ], fn ($part) => $part !== '')));
        }

        return ['phone' => $phone, 'address' => $address];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstFilled(array $data, array $keys): string
    {
        $wanted = array_map(fn (string $key) => strtolower($key), $keys);
        foreach ($data as $key => $value) {
            if (is_array($value) && $value !== []) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (! is_array($item) || $item === []) {
                            continue;
                        }
                        $nested = $this->firstFilled($item, $keys);
                        if ($nested !== '') {
                            return $nested;
                        }
                    }
                    continue;
                }
                $nested = $this->firstFilled($value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
                continue;
            }
            if (! in_array(strtolower((string) $key), $wanted, true)) {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
