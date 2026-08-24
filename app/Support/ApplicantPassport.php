<?php

namespace App\Support;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApplicantPassport
{
    public static function relativePathForApplication(Application $application): ?string
    {
        $application->loadMissing(['documents', 'steps', 'user.student', 'user.latestNinVerification']);

        $paths = [];
        $passport = $application->documents->firstWhere('doc_type', 'passport');
        if (! empty($passport?->path)) {
            $paths[] = (string) $passport->path;
        }

        $biodata = $application->steps->firstWhere('step_key', 'biodata')?->payload ?? [];
        if (! empty($biodata['photo_path'])) {
            $paths[] = (string) $biodata['photo_path'];
        }

        if (! empty($application->user?->student?->photo_path)) {
            $paths[] = (string) $application->user->student->photo_path;
        }

        $mapped = $application->user?->latestNinVerification?->mapped_fields ?? [];
        if (! empty($mapped['photo_path'])) {
            $paths[] = (string) $mapped['photo_path'];
        }

        foreach ($paths as $path) {
            if (self::absolutePath($path)) {
                return self::normalize($path);
            }
        }

        return null;
    }

    public static function relativePathForUser(User $user): ?string
    {
        $user->loadMissing(['student', 'latestNinVerification', 'latestApplication.documents', 'latestApplication.steps']);

        if ($user->student?->photo_path && self::absolutePath($user->student->photo_path)) {
            return self::normalize($user->student->photo_path);
        }

        if ($user->latestApplication) {
            $fromApp = self::relativePathForApplication($user->latestApplication);
            if ($fromApp) {
                return $fromApp;
            }
        }

        $mapped = $user->latestNinVerification?->mapped_fields ?? [];
        if (! empty($mapped['photo_path']) && self::absolutePath($mapped['photo_path'])) {
            return self::normalize($mapped['photo_path']);
        }

        return null;
    }

    public static function dataUriForApplication(Application $application): ?string
    {
        return self::dataUri(self::relativePathForApplication($application));
    }

    public static function dataUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'data:image')) {
            return $path;
        }

        $absolute = self::absolutePath($path);
        if (! $absolute) {
            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolute));
    }

    public static function fileResponseForApplication(Application $application): BinaryFileResponse
    {
        return self::fileResponse(self::relativePathForApplication($application));
    }

    public static function fileResponseForUser(User $user): BinaryFileResponse
    {
        return self::fileResponse(self::relativePathForUser($user));
    }

    public static function fileResponse(?string $path): BinaryFileResponse
    {
        $absolute = self::absolutePath($path);
        abort_unless($absolute, 404, 'Passport photograph not found.');

        $mime = mime_content_type($absolute) ?: 'image/jpeg';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="passport.jpg"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public static function absolutePath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return null;
        }

        $relative = self::normalize($path);
        $candidates = [
            Storage::disk('public')->path($relative),
            storage_path('app/public/'.$relative),
            storage_path('app/'.$relative),
        ];
        foreach ($candidates as $full) {
            if (is_file($full)) {
                return $full;
            }
        }

        if (Storage::disk('public')->exists($relative)) {
            return Storage::disk('public')->path($relative);
        }

        return null;
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path;
    }
}
