<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Intake;
use Illuminate\Validation\ValidationException;

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
        return self::acceptingIntakeNamesForSession($sessionId) === [];
    }

    public static function canSetCurrent(AcademicTerm $term): bool
    {
        return self::canSetCurrentForSession((int) $term->academic_session_id);
    }

    public static function assertCanSetCurrentForSession(int $sessionId, string $field = 'is_current'): void
    {
        $names = self::acceptingIntakeNamesForSession($sessionId);
        if ($names === []) {
            return;
        }

        $suffix = $names !== []
            ? ' Still accepting: '.implode(', ', $names).'.'
            : '';

        throw ValidationException::withMessages([
            $field => self::MESSAGE.$suffix,
        ]);
    }

    public static function assertCanSetCurrent(AcademicTerm $term, string $field = 'is_current'): void
    {
        self::assertCanSetCurrentForSession((int) $term->academic_session_id, $field);
    }
}
