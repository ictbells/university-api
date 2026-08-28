<?php

namespace App\Support;

use App\Models\AcademicLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ListSessionLevelFilter
{
    public static function sessionId(Request $request): ?int
    {
        $raw = $request->input('academic_session_id');
        if ($raw === null || $raw === '' || $raw === 'all' || $raw === '0') {
            return null;
        }

        return (int) $raw;
    }

    public static function levelCode(Request $request): ?string
    {
        $level = trim((string) $request->input('level', ''));

        return $level !== '' ? $level : null;
    }

    public static function applyToStudents(Builder $query, Request $request): void
    {
        if ($level = self::levelCode($request)) {
            $query->where('current_level', $level);
        }
        if ($sessionId = self::sessionId($request)) {
            $query->whereHas('application.intake.term', fn ($term) => $term->where('academic_session_id', $sessionId));
        }
    }

    public static function applyToStudentRelation(Builder $query, Request $request, string $relation = 'student'): void
    {
        if ($level = self::levelCode($request)) {
            $query->whereHas($relation, fn ($students) => $students->where('current_level', $level));
        }
        if ($sessionId = self::sessionId($request)) {
            $query->whereHas(
                $relation.'.application.intake.term',
                fn ($term) => $term->where('academic_session_id', $sessionId),
            );
        }
    }

    public static function applySessionToTermRelation(Builder $query, Request $request, string $termRelation = 'term'): void
    {
        if ($sessionId = self::sessionId($request)) {
            $query->whereHas($termRelation, fn ($term) => $term->where('academic_session_id', $sessionId));
        }
    }

    public static function applyLevelToCoursePrograms(Builder $query, Request $request, string $courseRelation = ''): void
    {
        $level = self::levelCode($request);
        if (! $level) {
            return;
        }
        $levelIds = AcademicLevel::idsMatching($level);
        if ($levelIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $relation = $courseRelation === '' ? 'programs' : $courseRelation.'.programs';
        $query->whereHas($relation, fn ($programs) => $programs->whereIn('program_course.academic_level_id', $levelIds));
    }
}
