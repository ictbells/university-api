<?php

namespace App\Support;

use App\Models\Grade;

/** Compose CA + exam into total score (0–100). */
final class GradeScoreComposer
{
    /**
     * @return array{ca_score: ?float, exam_score: ?float, score: ?float}
     */
    public static function compose(
        ?float $caScore,
        ?float $examScore,
        ?float $explicitTotal,
        bool $caProvided,
        bool $examProvided,
        bool $totalProvided,
        ?Grade $existing = null,
    ): array {
        $ca = $caProvided
            ? $caScore
            : ($existing?->ca_score !== null ? (float) $existing->ca_score : null);
        $exam = $examProvided
            ? $examScore
            : ($existing?->exam_score !== null ? (float) $existing->exam_score : null);

        if ($caProvided || $examProvided) {
            self::assertComponent($ca, 'CA');
            self::assertComponent($exam, 'Exam');
            $total = ($ca ?? 0.0) + ($exam ?? 0.0);
            if ($total > 100) {
                throw new \InvalidArgumentException('CA + exam must not exceed 100.');
            }

            return [
                'ca_score' => $ca,
                'exam_score' => $exam,
                'score' => $total,
            ];
        }

        if ($totalProvided) {
            if ($explicitTotal !== null && ($explicitTotal < 0 || $explicitTotal > 100)) {
                throw new \InvalidArgumentException('Score must be between 0 and 100.');
            }

            return [
                'ca_score' => $ca,
                'exam_score' => $exam,
                'score' => $explicitTotal,
            ];
        }

        return [
            'ca_score' => $ca,
            'exam_score' => $exam,
            'score' => $existing?->score !== null ? (float) $existing->score : null,
        ];
    }

    private static function assertComponent(?float $value, string $label): void
    {
        if ($value === null) {
            return;
        }
        if ($value < 0 || $value > 100) {
            throw new \InvalidArgumentException("{$label} score must be between 0 and 100.");
        }
    }

    public static function parseNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
