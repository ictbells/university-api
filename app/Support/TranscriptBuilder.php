<?php

namespace App\Support;

use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;

final class TranscriptBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function forStudent(
        Student $student,
        bool $releasedOnly = true,
        bool $includePendingHint = false,
        ?int $programId = null,
    ): array {
        $gradesQuery = Grade::query()
            ->withResolved()
            ->forStudent($student->id);

        if ($programId) {
            $courseIds = Program::query()
                ->whereKey($programId)
                ->first()
                ?->courses()
                ->pluck('courses.id')
                ->all() ?? [];

            if ($courseIds !== []) {
                $gradesQuery->where(function ($q) use ($courseIds) {
                    $q->whereHas('offering', fn ($o) => $o->whereIn('course_id', $courseIds))
                        ->orWhereHas('enrollment.offering', fn ($o) => $o->whereIn('course_id', $courseIds));
                });
            }
        }

        $grades = $gradesQuery->get();

        $allEligible = GpaCalculator::eligibleRows($grades, $releasedOnly);
        $cgpaSummary = GpaCalculator::summary($grades, $releasedOnly);

        $byTerm = $allEligible->groupBy(fn (Grade $g) => (int) ($g->resolvedOffering()?->academic_term_id ?? 0));
        $terms = [];
        foreach ($byTerm as $termId => $termGrades) {
            if (! $termId) {
                continue;
            }
            $first = $termGrades->first();
            $term = $first?->resolvedOffering()?->term;
            $summary = GpaCalculator::summary($termGrades, false);
            $terms[] = [
                'academic_term_id' => (int) $termId,
                'name' => $term?->name,
                'session_label' => $term?->session?->label ?: $term?->session_label,
                'gpa' => $summary['gpa'] ?? 0,
                'rows' => $termGrades->map(fn (Grade $g) => self::serializeGrade($g))->values()->all(),
            ];
        }

        usort($terms, fn ($a, $b) => ($a['academic_term_id'] <=> $b['academic_term_id']));

        $pendingCount = 0;
        if ($includePendingHint) {
            $pendingCount = $grades->filter(
                fn (Grade $g) => ! GradeStatus::isReleased((string) $g->status)
            )->count();
        }

        $flatRows = $allEligible->map(fn (Grade $g) => self::serializeGrade($g))->values()->all();

        return [
            'student' => $student->only(['id', 'student_number', 'matric_number', 'first_name', 'last_name']),
            'program_id' => $programId,
            'gpa' => $cgpaSummary['gpa'] ?? 0,
            'cgpa' => $cgpaSummary['gpa'] ?? 0,
            'total_credits' => $cgpaSummary['total_credits'],
            'terms' => $terms,
            'rows' => $flatRows,
            'pending_grades' => $pendingCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeGrade(Grade $grade): array
    {
        $offering = $grade->resolvedOffering();
        $course = $offering?->course;
        $term = $offering?->term;

        return [
            'id' => $grade->id,
            'enrollment_id' => $grade->enrollment_id,
            'sitting' => $grade->sitting,
            'letter' => $grade->resolvedLetter() ?: null,
            'points' => $grade->resolvedGradePoints(),
            'score' => $grade->score !== null && $grade->score !== '' ? (float) $grade->score : null,
            'ca_score' => $grade->ca_score !== null && $grade->ca_score !== '' ? (float) $grade->ca_score : null,
            'exam_score' => $grade->exam_score !== null && $grade->exam_score !== '' ? (float) $grade->exam_score : null,
            'status' => $grade->status,
            'registration_held' => (bool) $grade->registration_held,
            'released_at' => optional($grade->released_at)?->toIso8601String(),
            'course' => $course ? $course->only(['id', 'code', 'title', 'units']) : null,
            'term' => $term ? [
                'id' => $term->id,
                'name' => $term->name,
                'session_label' => $term->session?->label ?: $term->session_label,
            ] : null,
        ];
    }

    /**
     * Visible grade payload for student enrollment lists.
     *
     * @return array{grade: ?array, pending: bool}
     */
    public static function studentVisibleGrade(?Grade $grade): array
    {
        if (! $grade) {
            return ['grade' => null, 'pending' => false];
        }

        if (! GradeStatus::isReleased((string) $grade->status)) {
            return ['grade' => null, 'pending' => true];
        }

        return [
            'grade' => [
                'letter' => $grade->resolvedLetter() ?: null,
                'points' => $grade->resolvedGradePoints(),
                'score' => $grade->score !== null && $grade->score !== '' ? (float) $grade->score : null,
                'status' => $grade->status,
            ],
            'pending' => false,
        ];
    }
}
