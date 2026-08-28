<?php

namespace App\Support;

use App\Models\AcademicLevel;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\StudentProgrammeChange;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProgrammeChangeGpaPolicy
{
    /**
     * Official CGPA / transcript rows after change-of-programme rules.
     * Same college: all grades still count.
     * Different college: keep old-programme courses strictly below the new level
     * (300L→200L keeps old 100L only) plus every result earned on later programmes.
     *
     * @param  Collection<int, Grade>  $grades
     * @return Collection<int, Grade>
     */
    public static function forCgpa(Collection $grades, Student $student): Collection
    {
        $changes = self::changesFor($student);
        if ($changes->isEmpty()) {
            return $grades->values();
        }

        $levelsById = AcademicLevel::query()->get()->keyBy(fn (AcademicLevel $row) => (int) $row->id);
        $courseIds = $grades
            ->map(fn (Grade $grade) => (int) ($grade->resolvedOffering()?->course_id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $curriculumBands = self::curriculumBandsForCourses($changes, $courseIds, $levelsById);

        return $grades
            ->filter(fn (Grade $grade) => self::countsTowardCgpa($grade, $changes, $curriculumBands))
            ->values();
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return Collection<int, Enrollment>
     */
    public static function visibleEnrollments(Collection $enrollments, Student $student): Collection
    {
        $changes = self::changesFor($student);
        if ($changes->isEmpty()) {
            return $enrollments->values();
        }

        $courseIds = $enrollments
            ->map(fn (Enrollment $enrollment) => (int) ($enrollment->offering?->course_id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $levelsById = AcademicLevel::query()->get()->keyBy(fn (AcademicLevel $row) => (int) $row->id);
        $curriculumBands = self::curriculumBandsForCourses($changes, $courseIds, $levelsById);

        return $enrollments
            ->filter(function (Enrollment $enrollment) use ($changes, $curriculumBands) {
                $courseId = (int) ($enrollment->offering?->course_id ?? 0);
                $when = $enrollment->registered_at
                    ? Carbon::parse($enrollment->registered_at)
                    : ($enrollment->offering?->term?->session?->starts_on
                        ? Carbon::parse($enrollment->offering->term->session->starts_on)
                        : now());

                return self::countsAt($courseId, $when, $changes, $curriculumBands);
            })
            ->values();
    }

    /**
     * @return Collection<int, StudentProgrammeChange>
     */
    public static function changesFor(Student $student): Collection
    {
        if ($student->relationLoaded('programmeChanges')) {
            return $student->programmeChanges->sortBy('id')->values();
        }

        return StudentProgrammeChange::query()
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get();
    }

    public static function takenAt(Grade $grade): CarbonInterface
    {
        $registered = $grade->enrollment?->registered_at;
        if ($registered) {
            return Carbon::parse($registered);
        }
        $starts = $grade->resolvedOffering()?->term?->session?->starts_on;
        if ($starts) {
            return Carbon::parse($starts);
        }

        return $grade->created_at ? Carbon::parse($grade->created_at) : now();
    }

    /**
     * @param  Collection<int, StudentProgrammeChange>  $changes
     * @param  array<int, array<int, int>>  $curriculumBands
     */
    private static function countsTowardCgpa(
        Grade $grade,
        Collection $changes,
        array $curriculumBands,
    ): bool {
        $courseId = (int) ($grade->resolvedOffering()?->course_id ?? 0);

        return self::countsAt($courseId, self::takenAt($grade), $changes, $curriculumBands);
    }

    /**
     * @param  Collection<int, StudentProgrammeChange>  $changes
     * @param  array<int, array<int, int>>  $curriculumBands
     */
    private static function countsAt(
        int $courseId,
        CarbonInterface $when,
        Collection $changes,
        array $curriculumBands,
    ): bool {
        $ending = self::endingChangeAt($when, $changes);
        if (! $ending) {
            return true;
        }
        if ($ending->same_college) {
            return true;
        }
        $band = $curriculumBands[(int) $ending->from_program_id][$courseId] ?? 0;

        return $courseId > 0 && $band >= 1 && $band < LevelProgression::band((int) $ending->to_level);
    }

    /**
     * @param  Collection<int, StudentProgrammeChange>  $changes
     */
    private static function endingChangeAt(CarbonInterface $when, Collection $changes): ?StudentProgrammeChange
    {
        $ending = $changes->first();
        foreach ($changes as $change) {
            $at = $change->created_at instanceof CarbonInterface
                ? $change->created_at
                : Carbon::parse($change->created_at);
            if ($when->gte($at)) {
                $ending = null;
                continue;
            }
            $ending = $change;
            break;
        }

        return $ending;
    }

    /**
     * @param  Collection<int, StudentProgrammeChange>  $changes
     * @param  list<int>  $courseIds
     * @param  Collection<int, AcademicLevel>  $levelsById
     * @return array<int, array<int, int>>
     */
    private static function curriculumBandsForCourses(Collection $changes, array $courseIds, Collection $levelsById): array
    {
        $programIds = $changes->pluck('from_program_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($programIds === [] || $courseIds === []) {
            return [];
        }

        $rows = DB::table('program_course')
            ->whereIn('program_id', $programIds)
            ->whereIn('course_id', $courseIds)
            ->get(['program_id', 'course_id', 'academic_level_id']);

        $out = [];
        foreach ($rows as $row) {
            $programId = (int) $row->program_id;
            $courseId = (int) $row->course_id;
            $level = $levelsById->get((int) $row->academic_level_id);
            $out[$programId][$courseId] = LevelProgression::bandFromAcademicLevel(
                $level instanceof AcademicLevel ? $level : null,
            );
        }

        return $out;
    }
}
