<?php

namespace App\Support;

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
        if ($studentIds === []) {
            return [];
        }

        $all = Grade::query()
            ->with(['enrollment.offering.course', 'enrollment.student'])
            ->whereHas('enrollment', fn ($q) => $q->whereIn('student_id', $studentIds))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->get();

        $out = [];
        foreach ($studentIds as $studentId) {
            $studentGrades = $all->filter(fn (Grade $g) => (int) $g->enrollment?->student_id === (int) $studentId);
            $semester = $studentGrades->filter(
                fn (Grade $g) => (int) $g->enrollment?->offering?->academic_term_id === $academicTermId
            );
            $eligibleSem = GradeWorkflowService::preferSupplementary(
                $semester->filter(fn (Grade $g) => ! $g->registration_held)
            );
            $eligibleAll = GradeWorkflowService::preferSupplementary(
                $studentGrades->filter(fn (Grade $g) => ! $g->registration_held)
            );

            $semSummary = self::summarize($eligibleSem);
            $toDateSummary = self::summarize($eligibleAll);
            $failed = $eligibleAll
                ->filter(fn (Grade $g) => strtoupper((string) $g->letter) === 'F' || (float) $g->points === 0.0)
                ->map(fn (Grade $g) => $g->enrollment?->offering?->course?->code)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $cgpa = $toDateSummary['gpa'];
            $out[$studentId] = [
                'gpa' => $semSummary['gpa'],
                'cgpa' => $cgpa,
                'tur' => $semSummary['credits'],
                'tup' => $semSummary['passed_credits'],
                'wgp' => $semSummary['quality_points'],
                'tur_to_date' => $toDateSummary['credits'],
                'tup_to_date' => $toDateSummary['passed_credits'],
                'wgp_to_date' => $toDateSummary['quality_points'],
                'courses_failed' => $failed,
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
        $students = Student::query()
            ->with('program:id,name,code')
            ->whereIn('id', $studentIds)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($studentIds as $id) {
            $s = $students->get($id);
            $out[$id] = [
                'matric_number' => $s?->matric_number,
                'name' => trim(($s?->first_name ?? '').' '.($s?->last_name ?? '')),
                'level' => $s?->current_level,
                'programme' => $s?->program?->name,
                'year_of_entry' => $s?->year_of_entry ?? null,
                'mode_of_entry' => $s?->entry_mode ?? null,
            ];
        }

        return $out;
    }

    public static function format(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
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

    /**
     * @param  Collection<int, Grade>  $grades
     * @return array{gpa: ?float, credits: int, passed_credits: int, quality_points: float}
     */
    private static function summarize(Collection $grades): array
    {
        $credits = 0;
        $passed = 0;
        $qp = 0.0;
        foreach ($grades as $grade) {
            $units = (int) ($grade->enrollment?->offering?->course?->units ?? 0);
            if ($units <= 0) {
                continue;
            }
            $credits += $units;
            $point = (float) ($grade->points ?? 0);
            $qp += $point * $units;
            if (strtoupper((string) $grade->letter) !== 'F' && $point > 0) {
                $passed += $units;
            }
        }

        return [
            'gpa' => $credits > 0 ? round($qp / $credits, 2) : null,
            'credits' => $credits,
            'passed_credits' => $passed,
            'quality_points' => round($qp, 2),
        ];
    }

    public static function universityName(): string
    {
        return (string) Setting::getValue('university_name', 'Bells University of Technology');
    }
}
