<?php

namespace App\Support;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentLevelProgression;
use App\Services\GradeWorkflowService;
use App\Support\ProgrammeChangeGpaPolicy;

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
        if ($programId === null && ProgrammeChangeGpaPolicy::hasSubsequentAdmission($student) && $student->program_id) {
            $programId = (int) $student->program_id;
        }

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
        $visible = ProgrammeChangeGpaPolicy::forCgpa($allEligible, $student);
        $cgpaSummary = GpaCalculator::summary($visible, false);
        $hasCrossCollegeChange = ProgrammeChangeGpaPolicy::changesFor($student)
            ->contains(fn ($change) => ! $change->same_college);

        $program = $programId
            ? Program::query()->with('department.faculty')->find($programId)
            : null;
        $student->loadMissing(['program.department.faculty']);
        $program ??= $student->program;
        $levelsBySession = self::levelsBySession($student);

        $byTerm = $visible->groupBy(fn (Grade $g) => (int) ($g->resolvedOffering()?->academic_term_id ?? 0));
        $terms = [];
        foreach ($byTerm as $termId => $termGrades) {
            if (! $termId) {
                continue;
            }
            $first = $termGrades->first();
            $term = $first?->resolvedOffering()?->term;
            $sessionId = (int) ($term?->academic_session_id ?? 0);
            $summary = GpaCalculator::summary($termGrades, false);
            $credits = self::creditTotals($termGrades);
            $terms[] = [
                'academic_term_id' => (int) $termId,
                'academic_session_id' => $sessionId ?: null,
                'name' => $term?->name,
                'session_label' => $term?->session?->label ?: $term?->session_label,
                'level' => $levelsBySession[$sessionId] ?? ($student->current_level ? (int) $student->current_level : null),
                'gpa' => $summary['gpa'] ?? 0,
                'credits_offered' => $credits['offered'],
                'credits_passed' => $credits['passed'],
                'rows' => $termGrades
                    ->sortBy(fn (Grade $g) => strtoupper((string) ($g->resolvedOffering()?->course?->code ?? '')))
                    ->map(fn (Grade $g) => self::serializeGrade($g, true))
                    ->values()
                    ->all(),
            ];
        }

        usort($terms, fn ($a, $b) => ($a['academic_term_id'] <=> $b['academic_term_id']));

        $running = collect();
        foreach ($terms as $index => $termRow) {
            $termId = (int) $termRow['academic_term_id'];
            $termGrades = $byTerm->get($termId) ?? $byTerm->get((string) $termId) ?? collect();
            $running = $running->concat($termGrades);
            $runningSummary = GpaCalculator::summary($running, false);
            $terms[$index]['cgpa'] = $runningSummary['gpa'] ?? 0;
            $terms[$index]['heading'] = self::termHeading($terms[$index]);
        }

        $pendingCount = 0;
        if ($includePendingHint) {
            $pendingCount = ProgrammeChangeGpaPolicy::forCgpa(
                $grades->filter(fn (Grade $g) => ! $g->registration_held),
                $student,
            )->filter(
                fn (Grade $g) => ! GradeStatus::isReleased((string) $g->status)
            )->count();
        }

        $flatRows = $visible->map(fn (Grade $g) => self::serializeGrade($g, true))->values()->all();

        return [
            'student' => [
                ...$student->only(['id', 'student_number', 'matric_number', 'first_name', 'middle_name', 'last_name', 'current_level']),
                'full_name' => self::officialName($student),
                'programme' => $program?->name,
                'department' => $program?->department?->name,
                'college' => self::collegeLabel($program?->department?->faculty?->name),
            ],
            'program_id' => $programId,
            'gpa' => $cgpaSummary['gpa'] ?? 0,
            'cgpa' => $cgpaSummary['gpa'] ?? 0,
            'total_credits' => $cgpaSummary['total_credits'],
            'cgpa_note' => $hasCrossCollegeChange
                ? 'After a change of programme to a different college, the transcript and CGPA include old-programme courses below the new level only, plus results on the new programme.'
                : null,
            'terms' => $terms,
            'rows' => $flatRows,
            'pending_grades' => $pendingCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeGrade(Grade $grade, bool $countsTowardCgpa = true): array
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
            'grade_obtained' => self::formatGradeObtained($grade),
            'status' => $grade->status,
            'registration_held' => (bool) $grade->registration_held,
            'released_at' => optional($grade->released_at)?->toIso8601String(),
            'counts_toward_cgpa' => $countsTowardCgpa,
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
        $enrollments = ProgrammeChangeGpaPolicy::visibleEnrollments(
            $student->enrollments()
                ->enrolled()
                ->with([
                    'offering.course',
                    'offering.term.session',
                    'grades.offering.course',
                ])
                ->get(),
            $student,
        );

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
            'score' => self::unsignedTotalScore($released),
            'ca_score' => $released && $released->ca_score !== null && $released->ca_score !== '' ? (float) $released->ca_score : null,
            'exam_score' => $released && $released->exam_score !== null && $released->exam_score !== '' ? (float) $released->exam_score : null,
            'course' => $course ? $course->only(['id', 'code', 'title', 'units']) : null,
        ];
    }

    private static function unsignedTotalScore(?Grade $released): ?float
    {
        if (! $released) {
            return null;
        }
        if ($released->score !== null && $released->score !== '') {
            return (float) $released->score;
        }

        $hasCa = $released->ca_score !== null && $released->ca_score !== '';
        $hasExam = $released->exam_score !== null && $released->exam_score !== '';
        if (! $hasCa && ! $hasExam) {
            return null;
        }

        return (float) ($hasCa ? $released->ca_score : 0) + (float) ($hasExam ? $released->exam_score : 0);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Grade>  $grades
     * @return array{offered: int, passed: int}
     */
    private static function creditTotals($grades): array
    {
        $offered = 0;
        $passed = 0;
        foreach ($grades as $grade) {
            $units = $grade->courseUnits();
            $offered += $units;
            $letter = strtoupper($grade->resolvedLetter());
            if ($letter !== '' && $letter !== 'F') {
                $passed += $units;
            }
        }

        return ['offered' => $offered, 'passed' => $passed];
    }

    /**
     * @return array<int, int>
     */
    private static function levelsBySession(Student $student): array
    {
        $rows = StudentLevelProgression::query()
            ->with('session:id,starts_on')
            ->where('student_id', $student->id)
            ->get()
            ->sortBy(fn (StudentLevelProgression $row) => $row->session?->starts_on?->timestamp ?? PHP_INT_MAX)
            ->values();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->academic_session_id] = (int) $row->from_level;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private static function termHeading(array $term): string
    {
        $raw = strtoupper(trim((string) ($term['name'] ?? '')));
        $session = trim((string) ($term['session_label'] ?? ''));
        $level = $term['level'] ?? null;
        $isFirst = $raw === 'FIRST' || str_contains($raw, 'FIRST') || $raw === '1' || $raw === '1ST';
        $isSecond = $raw === 'SECOND' || str_contains($raw, 'SECOND') || $raw === '2' || $raw === '2ND';

        if ($isFirst) {
            return trim('FIRST SEMESTER'.($session !== '' ? ' '.$session : '').($level ? ' '.$level : ''));
        }
        if ($isSecond) {
            return 'SECOND SEMESTER';
        }

        $label = $raw !== ''
            ? (str_contains($raw, 'SEMESTER') ? $raw : $raw.' SEMESTER')
            : 'SEMESTER';

        return trim($label.($session !== '' ? ' '.$session : '').($level ? ' '.$level : ''));
    }

    private static function officialName(Student $student): string
    {
        return strtoupper(trim(collect([
            $student->last_name,
            $student->first_name,
            $student->middle_name,
        ])->filter(fn ($part) => filled($part))->implode(' ')));
    }

    private static function collegeLabel(?string $facultyName): ?string
    {
        $name = trim((string) $facultyName);
        if ($name === '') {
            return null;
        }
        if (stripos($name, 'college') !== false) {
            return strtoupper($name);
        }

        return 'COLLEGE OF '.strtoupper($name);
    }

    private static function formatGradeObtained(Grade $grade): string
    {
        $letter = $grade->resolvedLetter();
        $score = $grade->score;
        if ($score === null || $score === '') {
            return $letter !== '' ? $letter : '—';
        }
        $n = (float) $score;
        $shown = abs($n - round($n)) < 0.001
            ? (string) (int) round($n)
            : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        return $letter !== '' ? $shown.'('.$letter.')' : $shown;
    }
}
