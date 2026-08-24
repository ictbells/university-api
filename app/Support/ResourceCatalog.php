<?php

namespace App\Support;

class ResourceCatalog
{
    /**
     * @return array<int, array{slug: string, title: string, description: string, permission: string, filename: string, version: string, updated_at: string}>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'staff-portal-operations-sop',
                'title' => 'Staff Portal Operations SOP',
                'description' => 'Standard operating procedure for the Bells University staff portal: access control, office structure, academic and admissions setup, candidate and applicant import, security policies, and day-to-day administration.',
                'permission' => 'resources.view',
                'filename' => 'bells-staff-portal-operations-sop.md',
                'version' => '1.2',
                'updated_at' => '2026-08-24',
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $resource) {
            if ($resource['slug'] === $slug) {
                return $resource;
            }
        }

        return null;
    }

    public static function path(array $resource): string
    {
        return base_path('resources/sop/'.$resource['filename']);
    }
}
