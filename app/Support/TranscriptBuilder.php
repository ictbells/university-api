<?php

namespace App\Support;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Services\GradeWorkflowService;

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

    /**
     * Registered courses with released scores (or pending). Filterable by session/semester.
     *
     * @return array<string, mixed>
     */
    public static function unsignedForStudent(
        Student $student,
        ?int $sessionId = null,
        ?int $termId = null,
    ): array {
        $enrollments = $student->enrollments()
            ->enrolled()
            ->with([
                'offering.course',
                'offering.term.session',
                'grades.offering.course',
            ])
            ->get();

        $allTerms = [];
        $sessionsMap = [];

        foreach ($enrollments->groupBy(fn ($enrollment) => (int) ($enrollment->offering?->academic_term_id ?? 0)) as $groupedTermId => $termEnrollments) {
            if (! $groupedTermId) {
                continue;
            }

            $term = $termEnrollments->first()?->offering?->term;
            $session = $term?->session;
            $sessionKey = (int) ($term?->academic_session_id ?? 0);
            $sessionLabel = $session?->label ?: $term?->session_label;

            $releasedGrades = collect();
            $rows = [];
            $unitsRegistered = 0;

            foreach ($termEnrollments->sortBy(fn ($enrollment) => strtoupper((string) ($enrollment->offering?->course?->code ?? ''))) as $enrollment) {
                $enrollment->grades->each(fn (Grade $grade) => $grade->setRelation('enrollment', $enrollment));
                $released = self::releasedGradeForEnrollment($enrollment);
                $course = $enrollment->offering?->course;
                $units = (int) ($course?->units ?? 0);
                $unitsRegistered += $units;
                if ($released) {
                    $releasedGrades->push($released);
                }
                $rows[] = self::serializeUnsignedRow($enrollment, $released);
            }

            $summary = GpaCalculator::summary($releasedGrades, true);
            $allTerms[] = [
                'academic_term_id' => (int) $groupedTermId,
                'academic_session_id' => $sessionKey ?: null,
                'name' => $term?->name,
                'session_label' => $sessionLabel,
                'is_current' => (bool) $term?->is_current,
                'gpa' => $summary['gpa'],
                'total_credits' => $summary['total_credits'],
                'units_registered' => $unitsRegistered,
                'rows' => $rows,
            ];

            if ($sessionKey) {
                $sessionsMap[$sessionKey] ??= [
                    'id' => $sessionKey,
                    'label' => $sessionLabel,
                    'is_current' => false,
                    'terms' => [],
                ];
                if ($term?->is_current) {
                    $sessionsMap[$sessionKey]['is_current'] = true;
                }
                $sessionsMap[$sessionKey]['terms'][(int) $groupedTermId] = [
                    'id' => (int) $groupedTermId,
                    'name' => $term?->name,
                    'is_current' => (bool) $term?->is_current,
                ];
            }
        }

        usort($allTerms, fn ($a, $b) => ($a['academic_term_id'] <=> $b['academic_term_id']));

        $sessions = array_values($sessionsMap);
        usort($sessions, fn ($a, $b) => ($a['id'] <=> $b['id']));
        foreach ($sessions as &$sessionRow) {
            $sessionRow['terms'] = array_values($sessionRow['terms']);
            usort($sessionRow['terms'], fn ($a, $b) => ($a['id'] <=> $b['id']));
        }
        unset($sessionRow);

        $filtered = $allTerms;
        if ($termId) {
            $filtered = array_values(array_filter(
                $allTerms,
                fn ($term) => (int) $term['academic_term_id'] === $termId,
            ));
        } elseif ($sessionId) {
            $filtered = array_values(array_filter(
                $allTerms,
                fn ($term) => (int) ($term['academic_session_id'] ?? 0) === $sessionId,
            ));
        }

        $filteredTermIds = collect($filtered)->pluck('academic_term_id')->all();
        $scopeGrades = collect();
        $unitsRegistered = 0;
        foreach ($filtered as $term) {
            $unitsRegistered += (int) ($term['units_registered'] ?? 0);
        }
        foreach ($enrollments as $enrollment) {
            if (! in_array((int) ($enrollment->offering?->academic_term_id ?? 0), $filteredTermIds, true)) {
                continue;
            }
            $released = self::releasedGradeForEnrollment($enrollment);
            if ($released) {
                $scopeGrades->push($released);
            }
        }
        $scopeSummary = GpaCalculator::summary($scopeGrades, true);

        return [
            'student' => $student->only(['id', 'student_number', 'matric_number', 'first_name', 'last_name']),
            'official' => false,
            'unsigned' => true,
            'can_sign' => false,
            'notice' => 'Unsigned transcript of registered courses. Released scores are shown; pending results appear until they are released. This copy is not signed and is not valid for official use.',
            'sessions' => $sessions,
            'selected' => [
                'academic_session_id' => $sessionId,
                'academic_term_id' => $termId,
            ],
            'scope_label' => self::unsignedScopeLabel($sessions, $allTerms, $sessionId, $termId),
            'gpa' => $scopeSummary['gpa'],
            'total_credits' => $scopeSummary['total_credits'],
            'units_registered' => $unitsRegistered,
            'terms' => $filtered,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     * @param  list<array<string, mixed>>  $allTerms
     */
    private static function unsignedScopeLabel(array $sessions, array $allTerms, ?int $sessionId, ?int $termId): string
    {
        if ($termId) {
            $term = collect($allTerms)->firstWhere('academic_term_id', $termId);
            if (! is_array($term)) {
                return 'Selected semester';
            }

            return trim(($term['session_label'] ?? '').' · '.($term['name'] ?? '')) ?: 'Selected semester';
        }
        if ($sessionId) {
            $session = collect($sessions)->firstWhere('id', $sessionId);
            $label = is_array($session) ? trim((string) ($session['label'] ?? '')) : '';

            return $label !== '' ? $label.' (all semesters)' : 'Selected session (all semesters)';
        }

        return 'All registered sessions';
    }

    private static function releasedGradeForEnrollment(Enrollment $enrollment): ?Grade
    {
        $released = $enrollment->grades->filter(
            fn (Grade $grade) => GradeStatus::isReleased((string) $grade->status) && ! $grade->registration_held
        );

        return GradeWorkflowService::preferSupplementary($released)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeUnsignedRow(Enrollment $enrollment, ?Grade $released): array
    {
        $course = $enrollment->offering?->course;

        return [
            'enrollment_id' => $enrollment->id,
            'grade_id' => $released?->id,
            'is_carry_over' => (bool) $enrollment->is_carry_over,
            'result_status' => $released ? 'released' : 'pending',
            'pending' => $released === null,
            'letter' => $released ? ($released->resolvedLetter() ?: null) : null,
            'points' => $released ? $released->resolvedGradePoints() : null,
            'score' => $released && $released->score !== null && $released->score !== '' ? (float) $released->score : null,
            'ca_score' => $released && $released->ca_score !== null && $released->ca_score !== '' ? (float) $released->ca_score : null,
            'exam_score' => $released && $released->exam_score !== null && $released->exam_score !== '' ? (float) $released->exam_score : null,
            'course' => $course ? $course->only(['id', 'code', 'title', 'units']) : null,
        ];
    }
}
