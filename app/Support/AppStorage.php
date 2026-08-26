<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Application file storage. Honours FILESYSTEM_DISK (local or s3).
 */
final class AppStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function isRemote(): bool
    {
        $driver = config('filesystems.disks.'.self::diskName().'.driver', 'local');

        return $driver !== 'local';
    }

    /**
     * Disks to check when reading legacy paths (default first, then public/local).
     *
     * @return list<string>
     */
    public static function readDiskNames(): array
    {
        $names = [self::diskName(), 'public', 'local'];

        return array_values(array_unique($names));
    }

    public static function exists(string $path): bool
    {
        foreach (self::readDiskNames() as $name) {
            try {
                if (Storage::disk($name)->exists($path)) {
                    return true;
                }
            } catch (Throwable) {
                // ignore missing/misconfigured disks
            }
        }

        return false;
    }

    public static function get(string $path): string
    {
        foreach (self::readDiskNames() as $name) {
            try {
                $disk = Storage::disk($name);
                if ($disk->exists($path)) {
                    return (string) $disk->get($path);
                }
            } catch (Throwable) {
                // continue
            }
        }

        throw new RuntimeException('File not found: '.$path);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function response(string $path, ?string $name = null, array $headers = [])
    {
        foreach (self::readDiskNames() as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    return $disk->response($path, $name, $headers);
                }
            } catch (Throwable) {
                // continue
            }
        }

        abort(404, 'File not found.');
    }

    public static function download(string $path, ?string $name = null)
    {
        foreach (self::readDiskNames() as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    return $disk->download($path, $name);
                }
            } catch (Throwable) {
                // continue
            }
        }

        abort(404, 'File not found.');
    }

    public static function mimeType(string $path, string $fallback = 'application/octet-stream'): string
    {
        foreach (self::readDiskNames() as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    return $disk->mimeType($path) ?: $fallback;
                }
            } catch (Throwable) {
                // continue
            }
        }

        return $fallback;
    }

    /**
     * Local filesystem path for tools that need a real file (PhpSpreadsheet, etc.).
     * Remote disks are copied to a temp file; caller should delete when $isTemp is true.
     *
     * @return array{0: string, 1: bool} [path, isTemp]
     */
    public static function localCopy(string $path): array
    {
        foreach (self::readDiskNames() as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if (! $disk->exists($path)) {
                    continue;
                }

                $driver = config('filesystems.disks.'.$diskName.'.driver', 'local');
                if ($driver === 'local') {
                    try {
                        $full = $disk->path($path);
                        if (is_file($full)) {
                            return [$full, false];
                        }
                    } catch (Throwable) {
                        // fall through to copy
                    }
                }

                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $tmp = tempnam(sys_get_temp_dir(), 'appstore_');
                if ($tmp === false) {
                    throw new RuntimeException('Unable to create a temporary file.');
                }
                if ($ext !== '') {
                    $named = $tmp.'.'.$ext;
                    @rename($tmp, $named);
                    $tmp = $named;
                }
                file_put_contents($tmp, $disk->get($path));

                return [$tmp, true];
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'Unable to create')) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('File not found: '.$path);
    }

    public static function deleteLocalCopy(string $path, bool $isTemp): void
    {
        if ($isTemp && is_file($path)) {
            @unlink($path);
        }
    }

    public static function url(string $path): string
    {
        $disk = self::disk();
        try {
            if (self::isRemote() && method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl($path, now()->addMinutes(30));
            }
        } catch (Throwable) {
            // fall through
        }

        try {
            return $disk->url($path);
        } catch (Throwable) {
            return url('storage/'.ltrim($path, '/'));
        }
    }
}
