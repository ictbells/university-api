<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Throwable;

class RegistrarSignature
{
    public const PATH_KEY = 'registrar_signature_path';

    public static function relativePath(): ?string
    {
        $path = trim((string) Setting::getValue(self::PATH_KEY, ''));

        return $path !== '' ? $path : null;
    }

    public static function exists(): bool
    {
        $path = self::relativePath();

        return $path !== null && AppStorage::exists($path);
    }

    public static function dataUri(): ?string
    {
        $path = self::relativePath();
        if ($path === null || ! AppStorage::exists($path)) {
            return null;
        }

        try {
            $binary = AppStorage::get($path);
        } catch (Throwable) {
            return null;
        }

        $mime = AppStorage::mimeType($path, 'image/png');

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    public static function store(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: 'png'));
        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $extension = 'png';
        }

        self::deleteStoredFile();
        $path = $file->storeAs(
            'signatures',
            'registrar.'.$extension,
            AppStorage::diskName(),
        );
        Setting::setValue(self::PATH_KEY, $path);

        return $path;
    }

    public static function delete(): void
    {
        self::deleteStoredFile();
        Setting::setValue(self::PATH_KEY, '');
    }

    private static function deleteStoredFile(): void
    {
        $path = self::relativePath();
        if ($path === null) {
            return;
        }

        try {
            AppStorage::disk()->delete($path);
        } catch (Throwable) {
            // ignore missing disks
        }
    }
}
