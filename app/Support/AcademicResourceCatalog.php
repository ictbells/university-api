<?php

namespace App\Support;

class AcademicResourceCatalog
{
    /** @var array<string, string> */
    public const PERMISSIONS = [
        'campuses' => 'academic.campuses.manage',
        'colleges' => 'academic.colleges.manage',
        'departments' => 'academic.departments.manage',
        'sessions' => 'academic.sessions.manage',
        'graduation' => 'academic.graduate',
        'levels' => 'academic.levels.manage',
        'courses' => 'academic.courses.manage',
        'offerings' => 'academic.offerings.manage',
        'course-registration' => 'academic.enrollments.manage',
        'unit-limits' => 'academic.enrollments.manage',
        'registration-extensions' => 'academic.extensions.review',
        'programmes' => 'academic.programmes.manage',
        'intakes' => 'academic.intakes.manage',
        'olevel' => 'academic.olevel.manage',
    ];

    public static function permission(string $resourceKey): ?string
    {
        return self::PERMISSIONS[$resourceKey] ?? null;
    }

    public static function isValidKey(string $resourceKey): bool
    {
        return isset(self::PERMISSIONS[$resourceKey]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
