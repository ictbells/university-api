<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Grade;
use App\Services\GradeWorkflowService;
use App\Services\StudentTermSanctionService;
use Illuminate\Support\Collection;

final class SubmissionListReportBuilder
{
    /**
     * @param  Collection<int, Grade>  $rows
     * @return array<string, mixed>
     */
    public static function build(
        Collection $rows,
        AcademicTerm $term,
        string $scope,
        ?string $status,
        ?Faculty $faculty = null,
        ?Department $department = null,
        ?string $level = null,
        string $step = 'department',
    ): array {
        $term->loadMissing('session');

        $metricsStatus = $scope === 'board' ? null : $status;
        $studentIds = $rows->map(fn (Grade $g) => self::studentId($g))->filter()->unique()->values()->all();
        $metrics = StudentProgressMetrics::forStudents($studentIds, (int) $term->id, $metricsStatus);

        $facultyName = $faculty?->name
            ?? $department?->faculty?->name
            ?? $rows->first()?->department?->faculty?->name
            ?? '—';

        $sessionLabel = $term->session?->label ?: (string) $term->session_label;
        $semesterKey = self::semesterKey((string) $term->name);
        $isSupplementary = self::isSupplementaryCollection($rows);
        $step = self::normalizeStep($step, $scope);

        $institution = ReceiptInstitution::details();

        $base = [
            'university' => StudentProgressMetrics::universityName(),
            'campus_address' => $institution['address'] !== ''
                ? $institution['address']
                : 'KM 8, Idi-Iroko Road, Ota, Ogun State',
            'faculty_name' => self::collegeName($facultyName),
            'college_name' => self::collegeName($facultyName),
            'department_name' => ($scope === 'department' || ($scope === 'board' && $department))
                ? self::prefixedDepartmentName($department?->name ?? '—')
                : null,
            'academic_year' => $sessionLabel,
            'session_label' => $sessionLabel,
            'semester_name' => $term->name,
            'semester_label' => $semesterKey === 'second' ? 'SECOND' : 'FIRST',
            'status_label' => self::statusLabel($status),
            'scope' => $scope,
            'step' => $step,
            'layout' => 'broadsheet',
            'filter_level' => $level,
            'is_supplementary' => $isSupplementary,
            'sitting_label' => $isSupplementary ? 'SUPPLEMENTARY' : '',
            'title' => $isSupplementary ? 'UNDERGRADUATE SUPPLEMENTARY RESULT' : 'UNDERGRADUATE SEMESTER RESULT',
            'generated_at' => now()->toDateTimeString(),
            'signatures' => self::signaturesForStep($step),
        ];

        return array_merge($base, self::buildSheets(
            $rows,
            $metrics,
            $term,
            $level,
            $department,
            $facultyName,
            $isSupplementary,
            $metricsStatus,
        ));
    }

    /** @param  Collection<int, Grade>  $rows */
    private static function isSupplementaryCollection(Collection $rows): bool
    {
        if ($rows->isEmpty()) {
            return false;
        }

        return $rows->every(
            fn (Grade $r) => ($r->sitting ?? GradeStatus::SITTING_MAIN) === GradeStatus::SITTING_SUPPLEMENTARY
        );
    }

    private static function semesterKey(string $termName): string
    {
        return str_contains(strtolower($termName), 'second') ? 'second' : 'first';
    }

    /**
     * @param  Collection<int, Grade>  $rows
     * @param  array<int, array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    private static function buildSheets(
        Collection $rows,
        array $metrics,
        AcademicTerm $term,
        ?string $level,
        ?Department $department,
        string $facultyName,
        bool $isSupplementary,
        ?string $metricsStatus,
    ): array {
        $scoped = GradeWorkflowService::preferSupplementary(
            $rows->filter(fn (Grade $g) => ! $g->registration_held)->values()
        );
        $studentIds = $scoped->map(fn (Grade $g) => self::studentId($g))->filter()->unique()->values()->all();
        $profiles = StudentProgressMetrics::sheetProfiles($studentIds);
        $termGrades = self::termGradesForStudents($studentIds, (int) $term->id, $metricsStatus);
        $byStudent = $termGrades->groupBy(fn (Grade $g) => self::studentId($g));

        $groups = [];
        foreach ($studentIds as $studentId) {
            $studentId = (int) $studentId;
            $profile = $profiles[$studentId] ?? [];
            if (! self::studentMatchesLevel($profile, $level)) {
                continue;
            }

            $deptId = self::sheetDepartmentId($profile, $department, $byStudent->get($studentId));
            $sheetLevel = $level && trim($level) !== ''
                ? trim($level)
                : (string) ($profile['level'] ?? '—');
            $programme = (string) ($profile['programme'] ?? '—');
            $key = $deptId.'|'.$sheetLevel.'|'.$programme;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'department_id' => $deptId,
                    'level' => $sheetLevel,
                    'programme' => $programme,
                    'student_ids' => [],
                ];
            }
            $groups[$key]['student_ids'][] = $studentId;
        }

        $deptIds = array_values(array_unique(array_filter(array_column($groups, 'department_id'))));
        $departments = Department::query()
            ->with('faculty')
            ->whereIn('id', $deptIds)
            ->get()
            ->keyBy('id');
        if ($department && ! $departments->has($department->id)) {
            $department->loadMissing('faculty');
            $departments->put($department->id, $department);
        }

        $sheets = [];
        $sheetNo = 1;
        uasort($groups, function (array $a, array $b) use ($departments) {
            $nameA = strtolower((string) ($departments->get($a['department_id'])?->name ?? ''));
            $nameB = strtolower((string) ($departments->get($b['department_id'])?->name ?? ''));
            $cmp = $nameA <=> $nameB;
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ((string) $a['level']) <=> ((string) $b['level']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((string) $a['programme']) <=> ((string) $b['programme']);
        });

        foreach ($groups as $group) {
            $dept = $departments->get($group['department_id']);
            $deptName = $dept?->name ?? ($group['department_id'] ? 'Department #'.$group['department_id'] : '—');
            $college = self::collegeName((string) ($dept?->faculty?->name ?: $facultyName));
            $sheetLevel = (string) $group['level'];
            $courseColumns = self::mainCourseColumns(
                $group['student_ids'],
                $byStudent,
                (int) $group['department_id'],
                $sheetLevel,
            );
            $courseCodes = array_column($courseColumns, 'code');

            $sortedIds = collect($group['student_ids'])->sortBy(function ($studentId) use ($profiles) {
                return strtolower((string) ($profiles[(int) $studentId]['matric_number'] ?? ''));
            })->values();

            $students = [];
            $sn = 1;
            $summary = [
                'good_standing' => 0,
                'not_good_standing' => 0,
                'absent_with_permission' => 0,
                'incomplete' => 0,
                'absent_without_permission' => 0,
                'rusticated' => 0,
                'sick' => 0,
                'total' => 0,
            ];
            $sanctions = StudentTermSanctionService::typesForStudents(
                $group['student_ids'],
                (int) $term->id,
            );
            foreach ($sortedIds as $studentId) {
                $studentId = (int) $studentId;
                $profile = $profiles[$studentId] ?? [
                    'year_of_entry' => '—',
                    'level' => '—',
                    'programme' => '—',
                    'matric_number' => '—',
                    'name' => '—',
                ];
                $m = $metrics[$studentId] ?? [];
                $studentRows = $byStudent->get($studentId, collect());
                $scores = [];
                foreach ($courseCodes as $code) {
                    $scores[$code] = '—';
                }
                $other = [];
                foreach ($studentRows as $row) {
                    $course = self::course($row);
                    $code = (string) ($course?->code ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $cell = self::scoreCell($row);
                    if (in_array($code, $courseCodes, true)) {
                        $scores[$code] = $cell;
                        continue;
                    }
                    $other[] = self::otherCourseLabel($course, $cell);
                }

                $notIn = array_values(array_filter(
                    is_array($m['courses_not_in_to_date'] ?? null) ? $m['courses_not_in_to_date'] : []
                ));
                $unitsNotIn = (int) ($m['units_not_in_to_date'] ?? 0);
                $cgpa = array_key_exists('cgpa', $m) && $m['cgpa'] !== null ? (float) $m['cgpa'] : null;
                $sanction = $sanctions[$studentId]
                    ?? StudentTermSanctionType::fromStudentship($profile['studentship_status'] ?? null);
                $status = BroadsheetStanding::classify(
                    $cgpa,
                    $unitsNotIn,
                    $profile['year_of_entry'] ?? null,
                    is_array($m['semester_remarks'] ?? null) ? $m['semester_remarks'] : [],
                    (bool) ($m['has_scored_semester'] ?? false),
                    (bool) ($m['semester_incomplete'] ?? false),
                    $sanction,
                );
                $bucket = BroadsheetStanding::summaryBucket($status);
                if (isset($summary[$bucket])) {
                    $summary[$bucket]++;
                }

                $students[] = [
                    'sn' => $sn++,
                    'matric' => (string) ($profile['matric_number'] ?? '—'),
                    'matric_number' => (string) ($profile['matric_number'] ?? '—'),
                    'name' => (string) ($profile['name'] ?? '—'),
                    'year_of_entry' => (string) ($profile['year_of_entry'] ?? '—'),
                    'mode_of_entry' => (string) ($profile['mode_of_entry'] ?? '—'),
                    'scores' => $scores,
                    'other_courses' => $other !== [] ? implode('; ', $other) : '—',
                    'tur' => StudentProgressMetrics::formatInt($m['tur'] ?? 0),
                    'tut' => StudentProgressMetrics::formatInt($m['tur'] ?? 0),
                    'tup' => StudentProgressMetrics::formatInt($m['tup'] ?? 0),
                    'tuf' => StudentProgressMetrics::formatInt($m['tuf'] ?? 0),
                    'wgp' => StudentProgressMetrics::formatInt($m['wgp'] ?? 0),
                    'gpa' => StudentProgressMetrics::format($m['gpa'] ?? null),
                    'sgpa' => StudentProgressMetrics::format($m['sgpa'] ?? $m['gpa'] ?? null),
                    'pgpa' => StudentProgressMetrics::format($m['pgpa'] ?? null),
                    'cgpa' => StudentProgressMetrics::format($m['cgpa'] ?? null),
                    'status' => $status,
                    'remark' => $status,
                    'outstanding' => StudentProgressMetrics::formatOutstanding(count($notIn), $unitsNotIn, $notIn),
                    'units_not_in_to_date' => StudentProgressMetrics::formatOutstanding(count($notIn), $unitsNotIn, $notIn),
                    'tur_to_date' => StudentProgressMetrics::formatInt($m['tur_to_date'] ?? 0),
                    'tup_to_date' => StudentProgressMetrics::formatInt($m['tup_to_date'] ?? 0),
                    'wgp_to_date' => StudentProgressMetrics::formatInt($m['wgp_to_date'] ?? 0),
                    'courses_not_in_to_date' => implode(', ', $notIn) ?: '—',
                    'courses_failed' => implode(', ', $m['courses_failed_codes'] ?? []) ?: '—',
                    'units_registered' => StudentProgressMetrics::formatInt($m['tur'] ?? 0),
                    'units_passed' => StudentProgressMetrics::formatInt($m['tup'] ?? 0),
                ];
            }

            $summary['total'] = count($students);
            $sheets[] = [
                'sheet_mark' => sprintf('%03d', $sheetNo++),
                'name' => self::prefixedDepartmentName($deptName),
                'department_name' => self::prefixedDepartmentName($deptName),
                'department_id' => $group['department_id'],
                'college_name' => $college,
                'programme' => $group['programme'],
                'level' => $sheetLevel,
                'level_label' => self::levelLabel($sheetLevel),
                'course_columns' => $courseColumns,
                'course_codes' => $courseCodes,
                'students' => $students,
                'summary' => $summary,
            ];
        }

        $first = $sheets[0] ?? null;

        return [
            'sheet_title' => $isSupplementary ? 'UNDERGRADUATE SUPPLEMENTARY RESULT' : 'UNDERGRADUATE SEMESTER RESULT',
            'sheet_level' => $first['level_label'] ?? self::levelLabel($level),
            'is_supplementary' => $isSupplementary,
            'show_name' => true,
            'course_codes' => $first['course_codes'] ?? [],
            'course_columns' => $first['course_columns'] ?? [],
            'course_headers' => $first['course_columns'] ?? [],
            'students' => $first['students'] ?? [],
            'departments' => $sheets,
            'sheets' => $sheets,
        ];
    }

    /**
     * @param  list<int>  $studentIds
     */
    private static function termGradesForStudents(array $studentIds, int $academicTermId, ?string $status): Collection
    {
        $studentIds = array_values(array_filter($studentIds));
        if ($studentIds === []) {
            return collect();
        }

        $query = Grade::query()
            ->withResolved()
            ->forTerm($academicTermId)
            ->where(function ($q) use ($studentIds) {
                $q->whereIn('student_id', $studentIds)
                    ->orWhereHas('enrollment', fn ($e) => $e->whereIn('student_id', $studentIds));
            });
        $status = $status !== null ? trim($status) : '';
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return GradeWorkflowService::preferSupplementary(
            $query->get()->filter(fn (Grade $g) => ! $g->registration_held)->values()
        );
    }

    /**
     * @param  list<int>  $studentIds
     * @param  Collection<int, Collection<int, Grade>>  $byStudent
     * @return list<array{code: string, units: int, status: string, header_meta: string}>
     */
    private static function mainCourseColumns(
        array $studentIds,
        Collection $byStudent,
        int $departmentId,
        string $sheetLevel,
    ): array {
        $courseMeta = [];
        foreach ($studentIds as $studentId) {
            foreach ($byStudent->get((int) $studentId, collect()) as $row) {
                $course = self::course($row);
                $code = (string) ($course?->code ?? '');
                if ($code === '' || isset($courseMeta[$code])) {
                    continue;
                }
                if (! self::isMainColumnCourse($course, $departmentId, $sheetLevel)) {
                    continue;
                }
                $units = (int) ($course?->units ?? 0);
                $status = self::courseStatusLabel($course);
                $courseMeta[$code] = [
                    'code' => $code,
                    'units' => $units,
                    'status' => $status,
                    'header_meta' => self::courseHeaderMeta($units, $status),
                ];
            }
        }
        ksort($courseMeta);

        return array_values($courseMeta);
    }

    private static function isMainColumnCourse(?Course $course, int $departmentId, string $sheetLevel): bool
    {
        if (! $course) {
            return false;
        }
        if ($departmentId > 0 && (int) $course->department_id !== $departmentId) {
            return false;
        }
        if (strtolower(trim((string) ($course->status ?? 'core'))) !== 'core') {
            return false;
        }

        return self::levelsMatch($sheetLevel, self::courseLevelFromCode((string) $course->code));
    }

    private static function courseLevelFromCode(string $code): ?string
    {
        if (preg_match('/(\d)/', $code, $match)) {
            return $match[1].'00';
        }

        return null;
    }

    private static function levelsMatch(string $sheetLevel, ?string $courseLevel): bool
    {
        $sheetDigit = preg_match('/(\d)/', $sheetLevel, $match) ? $match[1] : null;
        $courseDigit = preg_match('/(\d)/', (string) $courseLevel, $match2) ? $match2[1] : null;
        if ($sheetDigit === null || $courseDigit === null) {
            return true;
        }

        return $sheetDigit === $courseDigit;
    }

    private static function courseHeaderMeta(int $units, string $status): string
    {
        if ($units > 0 && $status !== '') {
            return '('.$units.') ('.$status.')';
        }
        if ($units > 0) {
            return '('.$units.')';
        }
        if ($status !== '') {
            return '('.$status.')';
        }

        return '';
    }

    private static function scoreCell(Grade $row): string
    {
        $remark = GradeExamRemark::label($row->exam_remark);
        if ($remark !== '') {
            return $remark;
        }
        $letter = $row->resolvedLetter();
        $score = $row->score !== null && $row->score !== ''
            ? (string) (int) round((float) $row->score)
            : '';
        if ($score !== '' && $letter !== '') {
            return $score.' ('.$letter.')';
        }
        if ($letter !== '') {
            return $letter;
        }
        if ($score !== '') {
            return $score;
        }

        return '—';
    }

    private static function otherCourseLabel(?Course $course, string $cell): string
    {
        $code = (string) ($course?->code ?? '');
        $units = (int) ($course?->units ?? 0);
        $status = self::courseStatusLabel($course) ?: 'C';

        return sprintf('%s(%d,%s)- %s', $code, $units, $status, $cell);
    }

    private static function courseStatusLabel(?Course $course): string
    {
        if (! $course) {
            return '';
        }

        return match (strtolower(trim((string) ($course->status ?? '')))) {
            'elective' => 'E',
            'required' => 'R',
            'core' => 'C',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  Collection<int, Grade>|null  $studentRows
     */
    private static function sheetDepartmentId(array $profile, ?Department $department, mixed $studentRows): int
    {
        if ($department) {
            return (int) $department->id;
        }
        $fromProgram = (int) ($profile['department_id'] ?? 0);
        if ($fromProgram > 0) {
            return $fromProgram;
        }
        $first = $studentRows instanceof Collection ? $studentRows->first() : null;
        if ($first instanceof Grade) {
            return (int) ($first->department_id
                ?: $first->resolvedOffering()?->course?->department_id
                ?: 0);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private static function studentMatchesLevel(array $profile, ?string $level): bool
    {
        $want = trim((string) $level);
        if ($want === '') {
            return true;
        }
        $have = (string) ($profile['level'] ?? '');
        $wantDigit = preg_match('/(\d+)/', $want, $match) ? $match[1] : $want;
        $haveDigit = preg_match('/(\d+)/', $have, $match2) ? $match2[1] : '';
        if ($haveDigit === '' || $have === '—') {
            return true;
        }

        return $haveDigit === $wantDigit
            || str_starts_with($haveDigit, $wantDigit)
            || str_starts_with($wantDigit, $haveDigit);
    }

    private static function levelLabel(?string $level): string
    {
        $level = trim((string) $level);
        if ($level === '' || $level === '—') {
            return '';
        }
        if (stripos($level, 'level') !== false) {
            return strtoupper($level);
        }

        return strtoupper($level).' LEVEL';
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private static function signaturesForStep(string $step): array
    {
        $all = [
            ['key' => 'hod', 'label' => "HOD's Signature and Date"],
            ['key' => 'dean', 'label' => "Dean's Signature and Date"],
            ['key' => 'codd', 'label' => "Chairman CODD Signature and Date"],
            ['key' => 'senate', 'label' => "Senate's Approval and Date"],
        ];
        $count = match ($step) {
            'department' => 1,
            'college' => 2,
            'deans' => 3,
            default => 4,
        };

        return array_slice($all, 0, $count);
    }

    private static function normalizeStep(string $step, string $scope): string
    {
        if (in_array($step, ['department', 'college', 'deans', 'senate'], true)) {
            return $step;
        }

        return match ($scope) {
            'faculty' => 'college',
            'board' => 'senate',
            default => 'department',
        };
    }

    private static function studentId(Grade $grade): int
    {
        return $grade->resolvedStudentId();
    }

    private static function course(Grade $grade): ?Course
    {
        return $grade->resolvedOffering()?->course;
    }

    private static function collegeName(string $name): string
    {
        $name = trim($name);

        return ($name === '' || $name === '—') ? '—' : $name;
    }

    private static function prefixedDepartmentName(string $name): string
    {
        $name = trim(preg_replace('/^Department of\s+/i', '', $name) ?? $name);
        if ($name === '' || $name === '—') {
            return 'Department of —';
        }
        if (stripos($name, 'department') === 0) {
            return $name;
        }

        return 'Department of '.$name;
    }

    private static function statusLabel(?string $status): string
    {
        if (! $status) {
            return 'Senate list';
        }

        return GradeStatus::label($status);
    }
}
