<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Grade;
use App\Services\GradeWorkflowService;
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
    ): array {
        $rows = GradeWorkflowService::preferSupplementary(
            $rows->filter(fn (Grade $g) => ! $g->registration_held)->values()
        );

        if ($level) {
            $rows = $rows->filter(
                fn (Grade $g) => (string) $g->enrollment?->student?->current_level === (string) $level
            )->values();
        }

        $studentIds = $rows->map(fn (Grade $g) => (int) $g->enrollment?->student_id)->filter()->unique()->values()->all();
        $metrics = StudentProgressMetrics::forStudents($studentIds, (int) $term->id, $status);
        $profiles = StudentProgressMetrics::sheetProfiles($studentIds);

        $facultyName = $faculty?->name
            ?? $department?->faculty?->name
            ?? $rows->first()?->department?->faculty?->name
            ?? '—';

        $base = [
            'university' => StudentProgressMetrics::universityName(),
            'faculty_name' => $facultyName,
            'department_name' => $department?->name,
            'session_label' => $term->session?->label ?: $term->session_label,
            'semester_name' => $term->name,
            'status_label' => $status ? str_replace('_', ' ', ucfirst($status)) : 'All',
            'scope' => $scope,
            'filter_level' => $level,
            'generated_at' => now()->toDateTimeString(),
        ];

        if ($scope === 'department') {
            return array_merge($base, self::buildStudentMatrix($rows, $metrics, $profiles));
        }

        return array_merge($base, self::buildFacultySummary($rows, $metrics, $profiles, $scope === 'board'));
    }

    /**
     * @param  Collection<int, Grade>  $rows
     * @param  array<int, array<string, mixed>>  $metrics
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<string, mixed>
     */
    private static function buildStudentMatrix(Collection $rows, array $metrics, array $profiles): array
    {
        $courses = $rows
            ->map(fn (Grade $g) => $g->enrollment?->offering?->course)
            ->filter()
            ->unique('id')
            ->sortBy('code')
            ->values();

        $byStudent = $rows->groupBy(fn (Grade $g) => (int) $g->enrollment?->student_id);

        $students = [];
        foreach ($byStudent as $studentId => $grades) {
            $profile = $profiles[$studentId] ?? [];
            $metric = $metrics[$studentId] ?? [];
            $courseCells = [];
            foreach ($courses as $course) {
                $grade = $grades->first(
                    fn (Grade $g) => (int) $g->enrollment?->offering?->course_id === (int) $course->id
                );
                $courseCells[] = [
                    'code' => $course->code,
                    'units' => $course->units,
                    'letter' => $grade?->letter,
                    'score' => $grade?->score,
                    'status' => $grade?->status,
                ];
            }
            $students[] = [
                'matric_number' => $profile['matric_number'] ?? '—',
                'name' => $profile['name'] ?? '—',
                'year_of_entry' => $profile['year_of_entry'] ?? '—',
                'mode_of_entry' => $profile['mode_of_entry'] ?? '—',
                'level' => $profile['level'] ?? '—',
                'courses' => $courseCells,
                'gpa' => StudentProgressMetrics::format($metric['gpa'] ?? null),
                'cgpa' => StudentProgressMetrics::format($metric['cgpa'] ?? null),
                'tur' => $metric['tur'] ?? 0,
                'tup' => $metric['tup'] ?? 0,
                'wgp' => StudentProgressMetrics::format($metric['wgp'] ?? null),
                'tur_to_date' => $metric['tur_to_date'] ?? 0,
                'tup_to_date' => $metric['tup_to_date'] ?? 0,
                'wgp_to_date' => StudentProgressMetrics::format($metric['wgp_to_date'] ?? null),
                'courses_failed' => implode(', ', $metric['courses_failed'] ?? []) ?: '—',
                'remark' => $metric['remark'] ?? '—',
            ];
        }

        usort($students, fn ($a, $b) => strcmp((string) $a['matric_number'], (string) $b['matric_number']));

        return [
            'layout' => 'department_matrix',
            'course_headers' => $courses->map(fn ($c) => [
                'code' => $c->code,
                'units' => $c->units,
            ])->all(),
            'students' => $students,
        ];
    }

    /**
     * @param  Collection<int, Grade>  $rows
     * @param  array<int, array<string, mixed>>  $metrics
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<string, mixed>
     */
    private static function buildFacultySummary(Collection $rows, array $metrics, array $profiles, bool $includeName): array
    {
        $byDept = $rows->groupBy(fn (Grade $g) => (int) ($g->department_id ?? 0));
        $sheets = [];

        foreach ($byDept as $deptId => $deptRows) {
            $deptName = $deptRows->first()?->department?->name ?? '—';
            $students = [];
            $byStudent = $deptRows->groupBy(fn (Grade $g) => (int) $g->enrollment?->student_id);
            foreach ($byStudent as $studentId => $grades) {
                $profile = $profiles[$studentId] ?? [];
                $metric = $metrics[$studentId] ?? [];
                $courseRows = $grades->map(fn (Grade $g) => [
                    'code' => $g->enrollment?->offering?->course?->code,
                    'title' => $g->enrollment?->offering?->course?->title,
                    'units' => $g->enrollment?->offering?->course?->units,
                    'ca' => $g->ca_score,
                    'exam' => $g->exam_score,
                    'total' => $g->score,
                    'letter' => $g->letter,
                    'points' => $g->points,
                ])->values()->all();

                $students[] = [
                    'matric_number' => $profile['matric_number'] ?? '—',
                    'name' => $includeName ? ($profile['name'] ?? '—') : null,
                    'level' => $profile['level'] ?? '—',
                    'courses' => $courseRows,
                    'gpa' => StudentProgressMetrics::format($metric['gpa'] ?? null),
                    'cgpa' => StudentProgressMetrics::format($metric['cgpa'] ?? null),
                    'units_registered' => $metric['tur'] ?? 0,
                    'units_passed' => $metric['tup'] ?? 0,
                    'courses_failed' => implode(', ', $metric['courses_failed'] ?? []) ?: '—',
                    'remark' => $metric['remark'] ?? '—',
                ];
            }
            usort($students, fn ($a, $b) => strcmp((string) $a['matric_number'], (string) $b['matric_number']));
            $sheets[] = [
                'department_name' => $deptName,
                'department_id' => $deptId,
                'students' => $students,
            ];
        }

        return [
            'layout' => $includeName ? 'board_summary' : 'faculty_summary',
            'include_name' => $includeName,
            'sheets' => $sheets,
            'signatures' => [
                'hod' => 'Head of Department',
                'dean' => 'Dean of Faculty',
            ],
        ];
    }
}
