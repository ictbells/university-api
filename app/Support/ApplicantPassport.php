<?php

namespace App\Support;

use App\Models\Application;
use App\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ApplicantPassport
{
    public static function relativePathForApplication(Application $application): ?string
    {
        foreach (self::candidatesForApplication($application) as $value) {
            if (self::storageExists($value)) {
                return self::normalize($value);
            }
        }

        return null;
    }

    public static function relativePathForUser(User $user): ?string
    {
        $user->loadMissing(['student', 'latestNinVerification', 'latestApplication.documents', 'latestApplication.steps']);

        if ($user->student?->photo_path && self::storageExists($user->student->photo_path)) {
            return self::normalize($user->student->photo_path);
        }

        if ($user->latestApplication) {
            $fromApp = self::relativePathForApplication($user->latestApplication);
            if ($fromApp) {
                return $fromApp;
            }
        }

        foreach (self::payloadPhotoValues($user->latestNinVerification?->mapped_fields ?? []) as $value) {
            if (self::storageExists($value)) {
                return self::normalize($value);
            }
        }

        return null;
    }

    public static function dataUriForApplication(Application $application): ?string
    {
        foreach (self::candidatesForApplication($application) as $value) {
            $uri = self::dataUri($value);
            if ($uri) {
                return $uri;
            }
        }

        return null;
    }

    public static function dataUri(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $embedded = self::embeddedDataUri($path);
        if ($embedded) {
            return $embedded;
        }

        if (! self::storageExists($path)) {
            return null;
        }

        try {
            $binary = AppStorage::get(self::normalize($path));
        } catch (Throwable) {
            return null;
        }

        $mime = self::mimeFromBinary($binary) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    public static function fileResponseForApplication(Application $application): BinaryFileResponse|StreamedResponse
    {
        foreach (self::candidatesForApplication($application) as $value) {
            $response = self::tryFileResponse($value);
            if ($response) {
                return $response;
            }
        }

        abort(404, 'Passport photograph not found.');
    }

    public static function fileResponseForUser(User $user): BinaryFileResponse|StreamedResponse
    {
        $user->loadMissing(['student', 'latestNinVerification', 'latestApplication.documents', 'latestApplication.steps']);

        $values = array_filter([
            $user->student?->photo_path,
        ]);
        if ($user->latestApplication) {
            $values = array_merge($values, self::candidatesForApplication($user->latestApplication));
        }
        $values = array_merge($values, self::payloadPhotoValues($user->latestNinVerification?->mapped_fields ?? []));

        foreach ($values as $value) {
            $response = self::tryFileResponse((string) $value);
            if ($response) {
                return $response;
            }
        }

        abort(404, 'Passport photograph not found.');
    }

    public static function fileResponse(?string $path): BinaryFileResponse|StreamedResponse
    {
        $response = self::tryFileResponse($path);
        abort_unless($response, 404, 'Passport photograph not found.');

        return $response;
    }

    /**
     * Prefer storage existence; keep absolutePath() for callers that still need a local file.
     */
    public static function absolutePath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return null;
        }

        $relative = self::normalize($path);
        if (! self::storageExists($relative)) {
            return null;
        }

        try {
            [$local] = AppStorage::localCopy($relative);

            return $local;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function candidatesForApplication(Application $application): array
    {
        $application->loadMissing(['documents', 'steps', 'user.student', 'user.latestNinVerification']);

        $values = [];
        $passport = $application->documents->firstWhere('doc_type', 'passport');
        if (! empty($passport?->path)) {
            $values[] = (string) $passport->path;
        }

        $biodata = $application->steps->firstWhere('step_key', 'biodata')?->payload ?? [];
        $values = array_merge($values, self::payloadPhotoValues(is_array($biodata) ? $biodata : []));

        if (! empty($application->user?->student?->photo_path)) {
            $values[] = (string) $application->user->student->photo_path;
        }

        $mapped = $application->user?->latestNinVerification?->mapped_fields ?? [];
        $values = array_merge($values, self::payloadPhotoValues(is_array($mapped) ? $mapped : []));

        return array_values(array_unique(array_filter($values, fn ($value) => is_string($value) && trim($value) !== '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function payloadPhotoValues(array $payload): array
    {
        $values = [];
        foreach (['photo_path', 'photo', 'photograph', 'image'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                $values[] = $payload[$key];
            }
        }

        return $values;
    }

    private static function tryFileResponse(?string $path): BinaryFileResponse|StreamedResponse|null
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $embedded = self::embeddedDataUri($path);
        if ($embedded && preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/s', $embedded, $matches)) {
            $binary = base64_decode($matches[2], true);
            if ($binary === false || $binary === '') {
                return null;
            }
            $extension = str_contains($matches[1], 'png') ? 'png' : 'jpg';
            $tmp = tempnam(sys_get_temp_dir(), 'passport');
            $file = $tmp.'.'.$extension;
            @rename($tmp, $file);
            file_put_contents($file, $binary);

            return response()->file($file, [
                'Content-Type' => $matches[1],
                'Content-Disposition' => 'inline; filename="passport.'.$extension.'"',
                'Cache-Control' => 'private, max-age=3600',
            ])->deleteFileAfterSend(true);
        }

        $relative = self::normalize($path);
        if (! self::storageExists($relative)) {
            return null;
        }

        try {
            $binary = AppStorage::get($relative);
        } catch (Throwable) {
            return null;
        }

        $mime = self::mimeFromBinary($binary) ?: 'image/jpeg';
        $extension = str_contains($mime, 'png') ? 'png' : 'jpg';

        return AppStorage::response($relative, 'passport.'.$extension, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="passport.'.$extension.'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private static function storageExists(string $path): bool
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return false;
        }

        return AppStorage::exists(self::normalize($path));
    }

    private static function mimeFromBinary(string $binary): ?string
    {
        if (str_starts_with($binary, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($binary, "\xff\xd8")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 0, 16), 'WEBP')) {
            return 'image/webp';
        }

        return null;
    }

    private static function embeddedDataUri(string $value): ?string
    {
        $value = trim($value);
        if (str_starts_with($value, 'data:image')) {
            return $value;
        }

        if (strlen($value) < 64) {
            return null;
        }
        if (str_contains($value, 'nin-photos/') || str_contains($value, 'applications/') || preg_match('/\.(jpe?g|png|gif|webp)$/i', $value)) {
            return null;
        }

        $binary = base64_decode($value, true);
        if ($binary === false || strlen($binary) < 32) {
            return null;
        }
        if (! str_starts_with($binary, "\xff\xd8") && ! str_starts_with($binary, "\x89PNG")) {
            return null;
        }

        $mime = str_starts_with($binary, "\x89PNG") ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        return $path;
    }
}
