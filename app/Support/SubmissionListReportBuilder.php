<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Grade;
use App\Services\GradeWorkflowService;
use Illuminate\Support\Collection;

final class SubmissionListReportBuilder
{
    /**
     * Column / layout rules:
     * - department: student matrix — course columns + totals; no name
     * - faculty: summary-of-results per department (no name) + HOD/Dean signature page
     * - board: faculty summary + student name + units-not-in course list
     *
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

        $base = [
            'university' => StudentProgressMetrics::universityName(),
            'faculty_name' => self::prefixedFacultyName($facultyName),
            'department_name' => ($scope === 'department' || ($scope === 'board' && $department))
                ? self::prefixedDepartmentName($department?->name ?? '—')
                : null,
            'academic_year' => $sessionLabel,
            'session_label' => $sessionLabel,
            'semester_name' => $term->name,
            'semester_label' => $semesterKey === 'second' ? 'Second' : 'First',
            'status_label' => self::statusLabel($status),
            'scope' => $scope,
            'filter_level' => $level,
            'is_supplementary' => $isSupplementary,
            'sitting_label' => $isSupplementary ? 'SUPPLEMENTARY' : '',
            'generated_at' => now()->toDateTimeString(),
        ];

        if ($scope === 'department') {
            return array_merge($base, self::buildStudentMatrix(
                $rows,
                $metrics,
                $department,
                $term,
                $level,
                $isSupplementary,
            ));
        }

        return array_merge($base, self::buildFacultySummary(
            $rows,
            $metrics,
            $term,
            $level,
            $facultyName,
            $scope === 'board',
            $isSupplementary,
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

    private static function examLine(AcademicTerm $term, bool $supplementary): string
    {
        $semesterLabel = self::semesterKey((string) $term->name) === 'second' ? 'SECOND' : 'FIRST';
        $kind = $supplementary ? 'SUPPLEMENTARY EXAMINATION RESULTS' : 'SEMESTER EXAMINATION RESULTS';
        $year = strtoupper((string) ($term->session?->label ?: $term->session_label));

        return $year.' '.$semesterLabel.' '.$kind;
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
    private static function buildStudentMatrix(
        Collection $rows,
        array $metrics,
        ?Department $department,
        AcademicTerm $term,
        ?string $level,
        bool $isSupplementary,
    ): array {
        $preferred = GradeWorkflowService::preferSupplementary(
            $rows->filter(fn (Grade $g) => ! $g->registration_held)->values()
        );

        $courseMeta = [];
        foreach ($preferred as $row) {
            $course = self::course($row);
            $code = (string) ($course?->code ?? '');
            if ($code === '' || isset($courseMeta[$code])) {
                continue;
            }
            $units = (int) ($course?->units ?? 0);
            $status = self::courseStatusLabel($course);
            $courseMeta[$code] = [
                'code' => $code,
                'units' => $units,
                'status' => $status,
                'header_meta' => match (true) {
                    $units > 0 && $status !== '' => "{$units}:{$status}",
                    $units > 0 => (string) $units,
                    $status !== '' => $status,
                    default => '',
                },
            ];
        }
        ksort($courseMeta);
        $courseColumns = array_values($courseMeta);
        $courseCodes = array_column($courseColumns, 'code');

        $studentIds = $preferred->map(fn (Grade $g) => self::studentId($g))->filter()->unique()->all();
        $profiles = StudentProgressMetrics::sheetProfiles($studentIds);

        $byStudent = $preferred->groupBy(fn (Grade $g) => self::studentId($g));
        $students = [];
        $sn = 1;
        $levelCounts = [];
        $programmeCounts = [];

        $sortedStudentIds = $byStudent->keys()->sortBy(function ($studentId) use ($profiles) {
            return strtolower((string) ($profiles[(int) $studentId]['matric_number'] ?? ''));
        })->values();

        foreach ($sortedStudentIds as $studentId) {
            $studentId = (int) $studentId;
            /** @var Collection<int, Grade> $studentRows */
            $studentRows = $byStudent->get($studentId);
            $profile = $profiles[$studentId] ?? [
                'year_of_entry' => '—',
                'mode_of_entry' => '—',
                'level' => '—',
                'programme' => '—',
                'matric_number' => '—',
                'name' => '—',
            ];
            $m = $metrics[$studentId] ?? [];

            if (($profile['level'] ?? '—') !== '—') {
                $levelCounts[$profile['level']] = ($levelCounts[$profile['level']] ?? 0) + 1;
            }
            if (($profile['programme'] ?? '—') !== '—') {
                $programmeCounts[$profile['programme']] = ($programmeCounts[$profile['programme']] ?? 0) + 1;
            }

            $scores = [];
            foreach ($courseCodes as $code) {
                $scores[$code] = '—';
            }
            foreach ($studentRows as $row) {
                $code = (string) (self::course($row)?->code ?? '');
                if ($code === '') {
                    continue;
                }
                $scores[$code] = $row->score !== null ? (string) $row->score : '—';
            }

            $students[] = [
                'sn' => $sn++,
                'matric' => (string) ($profile['matric_number'] ?? '—'),
                'matric_number' => (string) ($profile['matric_number'] ?? '—'),
                'year_of_entry' => (string) ($profile['year_of_entry'] ?? '—'),
                'mode_of_entry' => (string) ($profile['mode_of_entry'] ?? '—'),
                'scores' => $scores,
                'tur' => StudentProgressMetrics::formatInt($m['tur'] ?? 0),
                'tup' => StudentProgressMetrics::formatInt($m['tup'] ?? 0),
                'wgp' => StudentProgressMetrics::formatInt($m['wgp'] ?? 0),
                'gpa' => StudentProgressMetrics::format($m['gpa'] ?? null),
                'tur_to_date' => StudentProgressMetrics::formatInt($m['tur_to_date'] ?? 0),
                'tup_to_date' => StudentProgressMetrics::formatInt($m['tup_to_date'] ?? 0),
                'wgp_to_date' => StudentProgressMetrics::formatInt($m['wgp_to_date'] ?? 0),
                'courses_not_in_to_date' => implode(', ', $m['courses_not_in_to_date'] ?? []) ?: '—',
                'cgpa' => StudentProgressMetrics::format($m['cgpa'] ?? null),
                'courses_failed' => implode(', ', $m['courses_failed_codes'] ?? []) ?: '—',
                'remark' => (string) ($m['remark'] ?? '—'),
                'units_registered' => StudentProgressMetrics::formatInt($m['tur'] ?? 0),
                'units_passed' => StudentProgressMetrics::formatInt($m['tup'] ?? 0),
            ];
        }

        $deptName = $department?->name
            ?? $rows->first()?->department?->name
            ?? '—';

        $sheetLevel = $level && $level !== '' ? $level : '—';
        if ($sheetLevel === '—' && $levelCounts !== []) {
            arsort($levelCounts);
            $sheetLevel = (string) array_key_first($levelCounts);
        }
        $sheetProgramme = '—';
        if ($programmeCounts !== []) {
            arsort($programmeCounts);
            $sheetProgramme = (string) array_key_first($programmeCounts);
        }

        return [
            'layout' => 'student_matrix',
            'sheet_title' => $isSupplementary ? 'DEPARTMENTAL SUPPLEMENTARY RESULTS' : 'DEPARTMENTAL RESULTS',
            'sheet_subtitle' => trim(
                self::prefixedDepartmentName($deptName)
                .($sheetProgramme !== '—' ? ', '.$sheetProgramme : '')
            ),
            'sheet_exam_line' => self::examLine($term, $isSupplementary),
            'sheet_level' => $sheetLevel !== '—' ? $sheetLevel.' Level' : '',
            'is_supplementary' => $isSupplementary,
            'department_name' => self::prefixedDepartmentName($deptName),
            'show_name' => false,
            'show_units' => false,
            'show_failed' => false,
            'show_unit_totals' => true,
            'show_gpa' => true,
            'course_codes' => $courseCodes,
            'course_columns' => $courseColumns,
            'course_headers' => $courseColumns,
            'students' => $students,
            'departments' => [],
        ];
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
     * @param  Collection<int, Grade>  $rows
     * @param  array<int, array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    private static function buildFacultySummary(
        Collection $rows,
        array $metrics,
        AcademicTerm $term,
        ?string $level,
        string $facultyName,
        bool $forBoard,
        bool $isSupplementary,
    ): array {
        $preferred = GradeWorkflowService::preferSupplementary(
            $rows->filter(fn (Grade $g) => ! $g->registration_held)->values()
        );

        $sheetLevel = $level && $level !== '' ? $level : '';

        $grouped = $preferred
            ->sortBy(fn (Grade $r) => (string) ($r->department?->name ?? 'ZZZ'))
            ->groupBy(fn (Grade $r) => (int) ($r->department_id ?? 0));

        $departments = [];
        $sheetNo = $forBoard ? 3 : 2;

        foreach ($grouped as $departmentId => $deptRows) {
            /** @var Collection<int, Grade> $deptRows */
            $deptName = $deptRows->first()?->department?->name
                ?? ($departmentId ? "Department #{$departmentId}" : 'Unassigned department');

            $byStudent = $deptRows->groupBy(fn (Grade $r) => self::studentId($r));
            $studentIds = $byStudent->keys()->map(fn ($id) => (int) $id)->all();
            $profiles = StudentProgressMetrics::sheetProfiles($studentIds);

            $programmeCounts = [];
            $levelCounts = [];
            foreach ($studentIds as $sid) {
                $programme = (string) ($profiles[$sid]['programme'] ?? '—');
                if ($programme !== '—') {
                    $programmeCounts[$programme] = ($programmeCounts[$programme] ?? 0) + 1;
                }
                $lvl = (string) ($profiles[$sid]['level'] ?? '—');
                if ($lvl !== '—') {
                    $levelCounts[$lvl] = ($levelCounts[$lvl] ?? 0) + 1;
                }
            }
            $sheetProgramme = '—';
            if ($programmeCounts !== []) {
                arsort($programmeCounts);
                $sheetProgramme = (string) array_key_first($programmeCounts);
            }
            if ($sheetLevel === '' && $levelCounts !== []) {
                arsort($levelCounts);
                $sheetLevel = (string) array_key_first($levelCounts);
            }

            $sortedStudentIds = $byStudent->keys()->sortBy(function ($studentId) use ($profiles, $forBoard) {
                $profile = $profiles[(int) $studentId] ?? [];
                if ($forBoard) {
                    return strtolower((string) ($profile['name'] ?? $profile['matric_number'] ?? ''));
                }

                return strtolower((string) ($profile['matric_number'] ?? ''));
            })->values();

            $students = [];
            $sn = 1;
            foreach ($sortedStudentIds as $studentId) {
                $studentId = (int) $studentId;
                $profile = $profiles[$studentId] ?? [
                    'year_of_entry' => '—',
                    'mode_of_entry' => '—',
                    'programme' => '—',
                    'matric_number' => '—',
                    'name' => '—',
                ];
                $m = $metrics[$studentId] ?? [];
                $notInCodes = array_values(array_filter(
                    is_array($m['courses_not_in_to_date'] ?? null) ? $m['courses_not_in_to_date'] : []
                ));
                $unitsNotIn = (int) ($m['units_not_in_to_date'] ?? 0);
                if ($unitsNotIn <= 0 && $notInCodes !== []) {
                    $unitsNotIn = count($notInCodes);
                }
                $unitsNotInDisplay = '';
                if ($forBoard && ($unitsNotIn > 0 || $notInCodes !== [])) {
                    $unitsNotInDisplay = $unitsNotIn.' ( '.implode(', ', $notInCodes).' )';
                } elseif (! $forBoard && $unitsNotIn > 0) {
                    $unitsNotInDisplay = (string) $unitsNotIn;
                }

                $entry = [
                    'sn' => $sn++,
                    'matric' => (string) ($profile['matric_number'] ?? '—'),
                    'matric_number' => (string) ($profile['matric_number'] ?? '—'),
                    'year_of_entry' => (string) ($profile['year_of_entry'] ?? '—'),
                    'mode_of_entry' => (string) ($profile['mode_of_entry'] ?? '—'),
                    'tur' => StudentProgressMetrics::formatInt($m['tur'] ?? 0),
                    'tup' => StudentProgressMetrics::formatInt($m['tup'] ?? 0),
                    'wgp' => StudentProgressMetrics::formatInt($m['wgp'] ?? 0),
                    'gpa' => StudentProgressMetrics::format($m['gpa'] ?? null),
                    'tur_to_date' => StudentProgressMetrics::formatInt($m['tur_to_date'] ?? 0),
                    'tup_to_date' => StudentProgressMetrics::formatInt($m['tup_to_date'] ?? 0),
                    'units_not_in_to_date' => $unitsNotInDisplay,
                    'wgp_to_date' => StudentProgressMetrics::formatInt($m['wgp_to_date'] ?? 0),
                    'cgpa' => StudentProgressMetrics::format($m['cgpa'] ?? null),
                    'remark' => (string) ($m['remark'] ?? '—'),
                ];
                if ($forBoard) {
                    $entry['name'] = (string) ($profile['name'] ?? '—');
                }
                $students[] = $entry;
            }

            $departments[] = [
                'sheet_mark' => sprintf('%03d', $sheetNo++),
                'name' => self::prefixedDepartmentName($deptName),
                'department_name' => self::prefixedDepartmentName($deptName),
                'department_id' => $departmentId,
                'programme' => $sheetProgramme,
                'students' => $students,
            ];
        }

        return [
            'layout' => $forBoard ? 'board_summary' : 'faculty_summary',
            'sheet_banner' => $isSupplementary
                ? 'NON-FINAL YEAR STUDENTS — SUPPLEMENTARY'
                : 'NON-FINAL YEAR STUDENTS',
            'sheet_exam_line' => self::examLine($term, $isSupplementary),
            'sheet_level' => $sheetLevel,
            'is_supplementary' => $isSupplementary,
            'faculty_name' => self::prefixedFacultyName($facultyName),
            'signature_hod_title' => 'Head Of Department',
            'signature_dean_title' => 'Dean '.strtoupper(self::prefixedFacultyName($facultyName)),
            'show_student_name' => $forBoard,
            'show_wgp_to_date' => ! $forBoard,
            'departments' => $departments,
            'sheets' => $departments,
            'include_name' => $forBoard,
            'students' => [],
            'course_codes' => [],
            'show_name' => $forBoard,
            'show_units' => false,
            'show_failed' => false,
            'show_unit_totals' => false,
            'show_gpa' => true,
            'signatures' => [
                'hod' => 'Head Of Department',
                'dean' => 'Dean '.strtoupper(self::prefixedFacultyName($facultyName)),
            ],
        ];
    }

    private static function studentId(Grade $grade): int
    {
        return $grade->resolvedStudentId();
    }

    private static function course(Grade $grade): ?Course
    {
        return $grade->resolvedOffering()?->course;
    }

    private static function prefixedFacultyName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '—') {
            return 'Faculty of —';
        }
        if (stripos($name, 'faculty') === 0) {
            return $name;
        }

        return 'Faculty of '.$name;
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
