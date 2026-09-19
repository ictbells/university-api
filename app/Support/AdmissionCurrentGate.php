<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Intake;

/**
 * Application intakes may stay open while a semester is marked current.
 * Enrolled students need a live term for semester fee, registration, and calendar.
 * acceptingIntakeNamesForSession() remains for staff UI awareness only.
 */
class AdmissionCurrentGate
{
    public const MESSAGE = 'Stop accepting applications for this admission session before setting it current.';

    /**
     * Names of application sessions (intakes) that are still accepting on this admission session.
     *
     * @return list<string>
     */
    public static function acceptingIntakeNamesForSession(int $sessionId): array
    {
        if ($sessionId <= 0) {
            return [];
        }

        $termIds = AcademicTerm::query()
            ->where('academic_session_id', $sessionId)
            ->pluck('id');

        if ($termIds->isEmpty()) {
            return [];
        }

        return Intake::query()
            ->whereIn('academic_term_id', $termIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (Intake $intake) => $intake->isAcceptingApplications())
            ->map(fn (Intake $intake) => $intake->name)
            ->values()
            ->all();
    }

    public static function canSetCurrentForSession(int $sessionId): bool
    {
        return true;
    }

    public static function canSetCurrent(AcademicTerm $term): bool
    {
        return true;
    }

    public static function assertCanSetCurrentForSession(int $sessionId, string $field = 'is_current'): void
    {
        // No-op: live semester may be set while application sessions still accept.
    }

    public static function assertCanSetCurrent(AcademicTerm $term, string $field = 'is_current'): void
    {
        // No-op: live semester may be set while application sessions still accept.
    }
}
