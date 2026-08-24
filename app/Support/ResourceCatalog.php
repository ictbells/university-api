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
                'description' => 'Standard operating procedure for the Bells University staff portal: access control, office structure, HOD/unit-head approvals, academic and admissions setup, catalogue bulk import, hostel room bulk import, candidate and continuing-student import, invoice and wallet history import, security policies, and day-to-day administration.',
                'permission' => 'resources.view',
                'filename' => 'bells-staff-portal-operations-sop.md',
                'version' => '1.7',
                'updated_at' => '2026-08-25',
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
