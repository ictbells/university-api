<?php

namespace App\Support;

use App\Models\AcademicLevel;
use App\Models\Grade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            self::constrainStudentsByLevel($query, $level);
        }
        if ($sessionId = self::sessionId($request)) {
            $query->whereHas('application.intake.term', fn ($term) => $term->where('academic_session_id', $sessionId));
        }
    }

    public static function applyToStudentRelation(Builder $query, Request $request, string $relation = 'student'): void
    {
        if ($level = self::levelCode($request)) {
            $query->whereHas($relation, function (Builder $students) use ($level) {
                self::constrainStudentsByLevel($students, $level);
            });
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

    /**
     * JUPEB students keep current_level=100. Filter by the JUPEB catalog label/name,
     * and keep undergraduate 100L from pulling those same rows in.
     */
    public static function constrainStudentsByLevel(Builder $query, string $level): void
    {
        $raw = trim($level);
        if ($raw === '') {
            return;
        }

        $catalog = self::matchingCatalogLevels($raw);

        if (self::wantsJupebStudents($raw, $catalog)) {
            $query->where(function (Builder $builder) {
                $builder->where('study_level', StudyLevel::JUPEB)
                    ->orWhereHas('application', fn ($applications) => $applications->where('entry_mode', 'jupeb'))
                    ->orWhereHas('program', fn ($programs) => $programs->where('study_level', StudyLevel::JUPEB));
            });

            return;
        }

        $values = Grade::levelMatchValues($raw);
        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('current_level', $values);

        if (self::shouldExcludeJupebFromBand($raw, $catalog)) {
            $query->where(function (Builder $builder) {
                $builder->whereNull('study_level')
                    ->orWhere('study_level', '!=', StudyLevel::JUPEB);
            })->where(function (Builder $builder) {
                $builder->whereDoesntHave('application')
                    ->orWhereHas('application', fn ($applications) => $applications->where('entry_mode', '!=', 'jupeb'));
            });
        }
    }

    /**
     * @return Collection<int, AcademicLevel>
     */
    private static function matchingCatalogLevels(string $raw): Collection
    {
        $digits = preg_match('/(\d{2,3})/', $raw, $match) ? $match[1] : null;

        return AcademicLevel::query()
            ->where(function (Builder $query) use ($raw, $digits) {
                $query->where('code', $raw)->orWhere('name', $raw);
                if (ctype_digit($raw)) {
                    $query->orWhere('id', (int) $raw);
                }
                if ($digits) {
                    $query->orWhere('code', $digits)
                        ->orWhere('code', $digits.'L')
                        ->orWhere('name', 'like', $digits.'%');
                }
                if (preg_match('/jupeb/i', $raw)) {
                    $query->orWhere('study_level', StudyLevel::JUPEB)
                        ->orWhere('name', 'like', '%JUPEB%')
                        ->orWhere('code', 'like', '%JUPEB%');
                }
            })
            ->get(['id', 'name', 'code', 'study_level']);
    }

    /**
     * @param  Collection<int, AcademicLevel>  $catalog
     */
    private static function wantsJupebStudents(string $raw, Collection $catalog): bool
    {
        if (preg_match('/jupeb/i', $raw)) {
            return true;
        }

        if ($catalog->isEmpty()) {
            return false;
        }

        return $catalog->every(fn (AcademicLevel $level) => self::catalogLevelIsJupeb($level));
    }

    /**
     * @param  Collection<int, AcademicLevel>  $catalog
     */
    private static function shouldExcludeJupebFromBand(string $raw, Collection $catalog): bool
    {
        if (preg_match('/jupeb/i', $raw)) {
            return false;
        }

        if ($catalog->isNotEmpty() && $catalog->every(fn (AcademicLevel $level) => self::catalogLevelIsJupeb($level))) {
            return false;
        }

        $digits = preg_match('/(\d{2,3})/', $raw, $match) ? (int) $match[1] : null;

        return $digits !== null && $digits >= 100 && $digits <= 500;
    }

    private static function catalogLevelIsJupeb(AcademicLevel $level): bool
    {
        return strtolower((string) $level->study_level) === StudyLevel::JUPEB
            || (bool) preg_match('/jupeb/i', (string) $level->name)
            || (bool) preg_match('/jupeb/i', (string) $level->code);
    }
}
