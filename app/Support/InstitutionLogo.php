<?php

namespace App\Support;

class InstitutionLogo
{
    /**
     * Logo for API-generated documents (PDF, Excel, Word, receipts).
     * Served from this app's public directory only — staff and student
     * portals keep their own copies for separate hosting.
     */
    public static function path(): ?string
    {
        foreach ([
            public_path('images/logo.png'),
            public_path('logo.png'),
        ] as $logoPath) {
            if (is_file($logoPath)) {
                return $logoPath;
            }
        }

        return null;
    }

    public static function dataUri(): ?string
    {
        $path = self::path();
        if ($path === null) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
