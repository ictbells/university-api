<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Setting;
use App\Models\Student;
use App\Services\GradeWorkflowService;
use Illuminate\Support\Collection;

final class StudentProgressMetrics
{
    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<string, mixed>>
     */
    public static function forStudents(array $studentIds, int $academicTermId, ?string $status = null): array
    {
        $studentIds = array_values(array_unique(array_filter($studentIds)));
        if ($studentIds === []) {
            return [];
        }

        $term = AcademicTerm::query()->with('session')->find($academicTermId);
        if (! $term) {
            return [];
        }

        $query = Grade::query()
            ->withResolved()
            ->where(function ($q) use ($studentIds) {
                $q->whereIn('student_id', $studentIds)
                    ->orWhereHas('enrollment', fn ($e) => $e->whereIn('student_id', $studentIds));
            });

        $status = $status !== null ? trim($status) : '';
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $all = $query->get()->groupBy(fn (Grade $g) => $g->resolvedStudentId());

        $registrations = self::enrollmentsToDate($studentIds, $term);
        $semesterRegistrations = self::enrollmentsForTerm($studentIds, $academicTermId);
        $studentsById = Student::query()
            ->whereIn('id', $studentIds)
            ->with('programmeChanges')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($studentIds as $studentId) {
            /** @var Collection<int, Grade> $rows */
            $rows = $all->get($studentId, collect());
            $semesterRows = $rows->filter(
                fn (Grade $g) => (int) ($g->resolvedOffering()?->academic_term_id ?? 0) === $academicTermId
            );
            $rowsToDate = $rows->filter(function (Grade $g) use ($term) {
                $rowTerm = $g->resolvedOffering()?->term;
                if (! $rowTerm) {
                    return false;
                }

                return self::isOnOrBeforeTerm($rowTerm, $term);
            });

            $eligibleSemester = GradeWorkflowService::preferSupplementary(
                $semesterRows->filter(fn (Grade $g) => ! $g->registration_held)
            );
            $eligibleToDate = GradeWorkflowService::preferSupplementary(
                $rowsToDate->filter(fn (Grade $g) => ! $g->registration_held)
            );
            $eligibleAll = GradeWorkflowService::preferSupplementary(
                $rows->filter(fn (Grade $g) => ! $g->registration_held)
            );
            $student = $studentsById->get($studentId);
            if ($student) {
                $eligibleToDate = ProgrammeChangeGpaPolicy::forCgpa($eligibleToDate, $student);
                $eligibleAll = ProgrammeChangeGpaPolicy::forCgpa($eligibleAll, $student);
            }

            $semesterSummary = GpaCalculator::summary($semesterRows, false);
            $toDateSummary = GpaCalculator::summary($eligibleToDate, false);

            $tur = (int) (($semesterRegistrations[$studentId]['total_units'] ?? 0));
            if ($tur <= 0) {
                $tur = (int) $semesterSummary['total_credits'];
            }
            $wgp = (float) $semesterSummary['total_quality_points'];
            $tup = 0;
            $failedCodes = [];
            foreach ($eligibleSemester as $row) {
                $units = self::units($row);
                $letter = $row->resolvedLetter();
                if ($letter === '' || $letter === 'F') {
                    $code = (string) (self::courseCode($row) ?? '');
                    if ($letter === 'F' && $code !== '') {
                        $failedCodes[] = $code;
                    }

                    continue;
                }
                if ($units > 0) {
                    $tup += $units;
                }
            }
            $failedCodes = array_values(array_unique($failedCodes));

            $tupToDate = 0;
            foreach ($eligibleToDate as $row) {
                $units = self::units($row);
                $letter = $row->resolvedLetter();
                if ($letter === '' || $letter === 'F' || $units <= 0) {
                    continue;
                }
                $tupToDate += $units;
            }

            $registered = $registrations[$studentId] ?? ['total_units' => 0, 'courses' => []];
            $resultCourseIds = $eligibleToDate
                ->map(fn (Grade $g) => (int) ($g->resolvedOffering()?->course_id ?? 0))
                ->filter()
                ->unique()
                ->all();
            $notIn = [];
            $unitsNotIn = 0;
            foreach ($registered['courses'] as $courseId => $info) {
                if (! in_array((int) $courseId, $resultCourseIds, true)) {
                    $notIn[] = $info['code'];
                    $unitsNotIn += (int) ($info['units'] ?? 0);
                }
            }
            sort($notIn);

            $turToDate = (int) ($registered['total_units'] ?? 0);
            if ($turToDate <= 0) {
                $turToDate = (int) $toDateSummary['total_credits'];
            }

            $cgpa = GpaCalculator::compute($eligibleAll, false);

            $out[$studentId] = [
                'gpa' => $semesterSummary['gpa'],
                'cgpa' => $cgpa,
                'tur' => $tur,
                'tup' => $tup,
                'wgp' => $wgp,
                'tur_to_date' => $turToDate,
                'tup_to_date' => $tupToDate,
                'wgp_to_date' => (float) $toDateSummary['total_quality_points'],
                'units_not_in_to_date' => $unitsNotIn,
                'courses_failed' => count($failedCodes),
                'courses_failed_codes' => $failedCodes,
                'courses_not_in_to_date' => $notIn,
                'units_registered' => $tur,
                'units_passed' => $tup,
                'remark' => self::remark($cgpa),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array{matric_number: ?string, name: string, level: ?string, programme: ?string, year_of_entry: ?string, mode_of_entry: ?string}>
     */
    public static function sheetProfiles(array $studentIds): array
    {
        $defaults = [
            'matric_number' => null,
            'name' => '',
            'level' => '—',
            'programme' => '—',
            'year_of_entry' => '—',
            'mode_of_entry' => '—',
        ];
        $studentIds = array_values(array_unique(array_filter($studentIds)));
        if ($studentIds === []) {
            return [];
        }

        $students = Student::query()
            ->with(['program:id,name,code,award_type', 'application.intake.term'])
            ->whereIn('id', $studentIds)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($studentIds as $id) {
            $s = $students->get($id);
            $programme = (string) ($s?->program?->name ?? '');
            $award = (string) ($s?->program?->award_type ?? '');
            if ($programme !== '' && $award !== '') {
                $programme = $programme.' ['.$award.']';
            }

            $year = (string) ($s?->application?->intake?->term?->session_label ?: '');
            if ($year === '') {
                $year = self::yearFromMatric((string) ($s?->matric_number ?? ''));
            }

            $mode = (string) ($s?->application?->entry_mode ?: '');
            $out[$id] = [
                'matric_number' => $s?->matric_number,
                'name' => trim(($s?->last_name ?? '').' '.($s?->first_name ?? '').' '.($s?->middle_name ?? '')),
                'level' => $s?->current_level !== null && $s?->current_level !== '' ? (string) $s->current_level : '—',
                'programme' => $programme !== '' ? $programme : '—',
                'year_of_entry' => $year !== '' ? $year : '—',
                'mode_of_entry' => $mode !== '' ? strtoupper($mode) : '—',
            ];
        }

        foreach ($studentIds as $id) {
            $out[$id] = $out[$id] ?? $defaults;
        }

        return $out;
    }

    public static function format(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }

    public static function formatInt(int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return (string) (int) round((float) $value);
    }

    public static function remark(?float $cgpa): string
    {
        if ($cgpa === null) {
            return '—';
        }
        if ($cgpa < 1.0) {
            return 'Withdraw';
        }
        if ($cgpa < 1.5) {
            return 'Probation';
        }

        return 'Pass';
    }

    public static function universityName(): string
    {
        return (string) Setting::getValue('university_name', 'Bells University of Technology');
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array{total_units: int, courses: array<int, array{code: string, units: int}>}>
     */
    private static function enrollmentsForTerm(array $studentIds, int $academicTermId): array
    {
        $map = [];
        Enrollment::query()
            ->enrolled()
            ->with(['offering.course'])
            ->whereIn('student_id', $studentIds)
            ->whereHas('offering', fn ($q) => $q->where('academic_term_id', $academicTermId))
            ->get()
            ->each(function (Enrollment $row) use (&$map) {
                self::accumulateEnrollment($map, $row);
            });

        return $map;
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array{total_units: int, courses: array<int, array{code: string, units: int}>}>
     */
    private static function enrollmentsToDate(array $studentIds, AcademicTerm $cutoff): array
    {
        $map = [];
        Enrollment::query()
            ->enrolled()
            ->with(['offering.course', 'offering.term.session'])
            ->whereIn('student_id', $studentIds)
            ->get()
            ->filter(function (Enrollment $row) use ($cutoff) {
                $term = $row->offering?->term;
                if (! $term) {
                    return false;
                }

                return self::isOnOrBeforeTerm($term, $cutoff);
            })
            ->each(function (Enrollment $row) use (&$map) {
                self::accumulateEnrollment($map, $row);
            });

        return $map;
    }

    /**
     * @param  array<int, array{total_units: int, courses: array<int, array{code: string, units: int}>}>  $map
     */
    private static function accumulateEnrollment(array &$map, Enrollment $row): void
    {
        $sid = (int) $row->student_id;
        $cid = (int) ($row->offering?->course_id ?? 0);
        $code = (string) ($row->offering?->course?->code ?? '');
        if ($code === '' || $cid <= 0) {
            return;
        }
        $units = (int) ($row->offering?->course?->units ?? 0);

        if (! isset($map[$sid])) {
            $map[$sid] = ['total_units' => 0, 'courses' => []];
        }
        if (! isset($map[$sid]['courses'][$cid])) {
            $map[$sid]['courses'][$cid] = ['code' => $code, 'units' => $units];
            $map[$sid]['total_units'] += max(0, $units);
        }
    }

    public static function isOnOrBeforeTerm(AcademicTerm $row, AcademicTerm $cutoff): bool
    {
        return self::termSortKey($row) <= self::termSortKey($cutoff);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private static function termSortKey(AcademicTerm $term): array
    {
        $sessionStart = $term->session?->starts_on?->format('Y-m-d')
            ?? (string) ($term->session_label ?? '');
        $termStart = $term->starts_on?->format('Y-m-d') ?? '';

        return [$sessionStart, $termStart, (int) $term->id];
    }

    private static function units(Grade $grade): int
    {
        return $grade->courseUnits();
    }

    private static function courseCode(Grade $grade): ?string
    {
        $code = (string) ($grade->resolvedOffering()?->course?->code ?? '');

        return $code !== '' ? $code : null;
    }

    private static function yearFromMatric(string $matric): string
    {
        if (preg_match('/(20\d{2})/', $matric, $match)) {
            return $match[1];
        }

        return '';
    }
}
