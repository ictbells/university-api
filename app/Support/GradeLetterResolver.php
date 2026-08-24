<?php

namespace App\Support;

use App\Models\GradeBoundary;
use App\Models\GradingScale;

final class GradeLetterResolver
{
    public static function defaultScale(): ?GradingScale
    {
        return GradingScale::query()->where('is_default', true)->first()
            ?? GradingScale::query()->orderBy('id')->first();
    }

    /**
     * @return array{letter: string, grade_point: float}|null
     */
    public static function fromScore(float $score, ?GradingScale $scale = null): ?array
    {
        $scale ??= self::defaultScale();
        if (! $scale) {
            return null;
        }

        $boundary = GradeBoundary::query()
            ->where('grading_scale_id', $scale->id)
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->orderByDesc('min_score')
            ->first();

        if (! $boundary) {
            return null;
        }

        return [
            'letter' => (string) $boundary->letter,
            'grade_point' => (float) $boundary->grade_point,
        ];
    }

    public static function gradePointForLetter(string $letter, ?GradingScale $scale = null): ?float
    {
        $scale ??= self::defaultScale();
        if (! $scale) {
            return null;
        }

        $boundary = GradeBoundary::query()
            ->where('grading_scale_id', $scale->id)
            ->where('letter', strtoupper(trim($letter)))
            ->first();

        return $boundary ? (float) $boundary->grade_point : null;
    }
}
