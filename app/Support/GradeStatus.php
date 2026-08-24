<?php

namespace App\Support;

final class GradeStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const FACULTY_APPROVED = 'faculty_approved';

    public const BOARD_READY = 'board_ready';

    public const CORRECTION_REQUIRED = 'correction_required';

    public const BOARD_CLEARED = 'board_cleared';

    public const RELEASED = 'released';

    public const PUBLISHED = 'published';

    public const SITTING_MAIN = 'main';

    public const SITTING_SUPPLEMENTARY = 'supplementary';

    public const LANE_DEPARTMENTAL = 'departmental';

    public const LANE_GENERAL = 'general';

    public const LANE_FACULTY = 'faculty';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::SUBMITTED,
            self::FACULTY_APPROVED,
            self::BOARD_READY,
            self::CORRECTION_REQUIRED,
            self::BOARD_CLEARED,
            self::RELEASED,
        ];
    }

    /** @return list<string> */
    public static function editable(): array
    {
        return [self::DRAFT, self::CORRECTION_REQUIRED];
    }

    public static function isEditable(string $status): bool
    {
        return in_array($status, self::editable(), true);
    }

    public static function isReleased(string $status): bool
    {
        return $status === self::RELEASED || $status === self::PUBLISHED;
    }

    /** @return list<string> */
    public static function sittings(): array
    {
        return [self::SITTING_MAIN, self::SITTING_SUPPLEMENTARY];
    }

    /** @return list<string> */
    public static function lanes(): array
    {
        return [self::LANE_DEPARTMENTAL, self::LANE_GENERAL, self::LANE_FACULTY];
    }

    public static function laneFromCourseType(?string $courseType): ?string
    {
        return match (strtolower(trim((string) $courseType))) {
            'departmental', 'department' => self::LANE_DEPARTMENTAL,
            'general', 'general_studies', 'gst' => self::LANE_GENERAL,
            'faculty' => self::LANE_FACULTY,
            default => self::LANE_DEPARTMENTAL,
        };
    }
}
