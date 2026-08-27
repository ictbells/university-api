<?php

namespace App\Support;

use App\Models\Grade;
use App\Services\GradeWorkflowService;
use Illuminate\Support\Collection;

final class GpaCalculator
{
    /**
     * @param  Collection<int, Grade>  $grades
     */
    public static function compute(Collection $grades, bool $releasedOnly = true): ?float
    {
        $summary = self::summary($grades, $releasedOnly);

        return $summary['gpa'];
    }

    /**
     * @param  Collection<int, Grade>  $grades
     * @return array{gpa: ?float, total_credits: int, total_quality_points: float}
     */
    public static function summary(Collection $grades, bool $releasedOnly = true): array
    {
        $rows = self::eligibleRows($grades, $releasedOnly);

        $totalPoints = 0.0;
        $totalCredits = 0;

        foreach ($rows as $grade) {
            $credits = $grade->courseUnits();
            if ($credits <= 0) {
                continue;
            }
            $point = (float) ($grade->points ?? 0);
            $totalPoints += $point * $credits;
            $totalCredits += $credits;
        }

        return [
            'gpa' => $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : null,
            'total_credits' => $totalCredits,
            'total_quality_points' => round($totalPoints, 2),
        ];
    }

    /**
     * @param  Collection<int, Grade>  $grades
     * @return Collection<int, Grade>
     */
    public static function eligibleRows(Collection $grades, bool $releasedOnly): Collection
    {
        $rows = $releasedOnly
            ? $grades->filter(fn (Grade $g) => GradeStatus::isReleased((string) $g->status))
            : $grades;

        $rows = $rows->filter(fn (Grade $g) => ! $g->registration_held);

        return GradeWorkflowService::preferSupplementary($rows);
    }
}
