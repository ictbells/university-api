<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Grade;
use App\Models\GradeBoardScope;
use App\Support\GradeAuditLogger;
use App\Support\GradeStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GradeWorkflowService
{
    /**
     * @param  list<int>  $ids
     * @return array{updated: int, errors: list<string>}
     */
    public function submit(array $ids, Authenticatable $actor): array
    {
        return $this->transitionMany(
            $ids,
            [GradeStatus::DRAFT, GradeStatus::CORRECTION_REQUIRED],
            GradeStatus::SUBMITTED,
            $actor,
            fn (Grade $r) => [
                'submitted_at' => now(),
                'correction_note' => null,
            ],
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array{updated: int, errors: list<string>}
     */
    public function facultyApprove(array $ids, Authenticatable $actor): array
    {
        return $this->transitionMany(
            $ids,
            [GradeStatus::SUBMITTED],
            GradeStatus::BOARD_READY,
            $actor,
            fn (Grade $r) => [
                'status' => GradeStatus::BOARD_READY,
                'faculty_approved_at' => now(),
            ],
            viaStatus: GradeStatus::FACULTY_APPROVED,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array{updated: int, errors: list<string>}
     */
    public function facultyReturn(array $ids, Authenticatable $actor, ?string $note = null): array
    {
        return $this->transitionMany(
            $ids,
            [GradeStatus::SUBMITTED, GradeStatus::FACULTY_APPROVED, GradeStatus::BOARD_READY],
            GradeStatus::CORRECTION_REQUIRED,
            $actor,
            fn (Grade $r) => [
                'correction_note' => $note,
            ],
            note: $note,
        );
    }

    /**
     * @param  list<int>|null  $ids
     * @return array{updated: int, errors: list<string>}
     */
    public function boardClear(
        int $academicTermId,
        ?int $facultyId,
        ?int $departmentId,
        Authenticatable $actor,
        ?string $note = null,
        ?array $ids = null,
    ): array {
        $ids = $ids !== null && $ids !== []
            ? array_values(array_map('intval', $ids))
            : $this->scopedIds($academicTermId, $facultyId, $departmentId, [GradeStatus::BOARD_READY]);

        $result = $this->transitionMany(
            $ids,
            [GradeStatus::BOARD_READY],
            GradeStatus::BOARD_CLEARED,
            $actor,
            fn (Grade $r) => [
                'board_cleared_at' => now(),
            ],
            note: $note,
        );

        $this->upsertBoardScope(
            $departmentId ? 'department' : 'faculty',
            $facultyId,
            $departmentId,
            $academicTermId,
            'cleared',
            $actor,
            $note,
            cleared: true,
        );

        return $result;
    }

    /**
     * @param  list<int>|null  $ids
     * @return array{updated: int, errors: list<string>}
     */
    public function boardRequestCorrections(
        int $academicTermId,
        ?int $facultyId,
        ?int $departmentId,
        Authenticatable $actor,
        ?string $note = null,
        ?array $ids = null,
    ): array {
        $ids = $ids !== null && $ids !== []
            ? array_values(array_map('intval', $ids))
            : $this->scopedIds($academicTermId, $facultyId, $departmentId, [GradeStatus::BOARD_READY]);

        $result = $this->transitionMany(
            $ids,
            [GradeStatus::BOARD_READY],
            GradeStatus::CORRECTION_REQUIRED,
            $actor,
            fn (Grade $r) => [
                'correction_note' => $note,
            ],
            note: $note,
        );

        $this->upsertBoardScope(
            $departmentId ? 'department' : 'faculty',
            $facultyId,
            $departmentId,
            $academicTermId,
            'corrections',
            $actor,
            $note,
            corrections: true,
        );

        return $result;
    }

    /**
     * @param  list<int>  $ids
     * @return array{updated: int, errors: list<string>}
     */
    public function release(array $ids, Authenticatable $actor, bool $force = false): array
    {
        $from = $force
            ? [GradeStatus::BOARD_CLEARED, GradeStatus::BOARD_READY, GradeStatus::FACULTY_APPROVED]
            : [GradeStatus::BOARD_CLEARED];

        return $this->transitionMany(
            $ids,
            $from,
            GradeStatus::RELEASED,
            $actor,
            fn (Grade $r) => [
                'released_at' => now(),
            ],
        );
    }

    /**
     * @return array{updated: int, errors: list<string>}
     */
    public function releaseScope(
        int $academicTermId,
        ?int $facultyId,
        ?int $departmentId,
        Authenticatable $actor,
        bool $force = false,
    ): array {
        $statuses = $force
            ? [GradeStatus::BOARD_CLEARED, GradeStatus::BOARD_READY, GradeStatus::FACULTY_APPROVED]
            : [GradeStatus::BOARD_CLEARED];

        $ids = $this->scopedIds($academicTermId, $facultyId, $departmentId, $statuses);

        return $this->release($ids, $actor, $force);
    }

    /**
     * @return array{upload_lane: ?string, faculty_id: ?int, department_id: ?int}
     */
    public static function orgSnapshotFromCourse(Course $course): array
    {
        $course->loadMissing('department');

        return [
            'upload_lane' => GradeStatus::laneFromCourseType($course->course_type),
            'faculty_id' => $course->department?->faculty_id ? (int) $course->department->faculty_id : null,
            'department_id' => $course->department_id ? (int) $course->department_id : null,
        ];
    }

    /**
     * @param  Collection<int, Grade>  $grades
     * @return Collection<int, Grade>
     */
    public static function preferSupplementary(Collection $grades): Collection
    {
        return $grades
            ->groupBy(function (Grade $g) {
                $offering = $g->resolvedOffering();
                $termId = $offering?->academic_term_id ?? 0;
                $courseId = $offering?->course_id ?? 0;
                $studentId = $g->resolvedStudentId();

                return implode('|', [$studentId, $courseId, $termId]);
            })
            ->map(function (Collection $group) {
                $supp = $group->first(
                    fn (Grade $r) => ($r->sitting ?? GradeStatus::SITTING_MAIN) === GradeStatus::SITTING_SUPPLEMENTARY
                );

                return $supp ?? $group->first();
            })
            ->filter()
            ->values();
    }

    /**
     * @param  list<string>  $statuses
     * @return list<int>
     */
    private function scopedIds(int $academicTermId, ?int $facultyId, ?int $departmentId, array $statuses): array
    {
        $query = Grade::query()
            ->whereIn('status', $statuses)
            ->forTerm($academicTermId);

        if ($departmentId) {
            $query->forDepartment($departmentId);
        } elseif ($facultyId) {
            $query->forFaculty($facultyId, true);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $fromStatuses
     * @param  callable(Grade): array<string, mixed>  $extra
     * @return array{updated: int, errors: list<string>}
     */
    private function transitionMany(
        array $ids,
        array $fromStatuses,
        string $toStatus,
        Authenticatable $actor,
        callable $extra,
        ?string $note = null,
        ?string $viaStatus = null,
    ): array {
        $updated = 0;
        $errors = [];

        $rows = Grade::query()
            ->with(['enrollment', 'offering', 'student'])
            ->whereIn('id', $ids)
            ->get();

        DB::transaction(function () use ($rows, $fromStatuses, $toStatus, $actor, $extra, $note, $viaStatus, &$updated, &$errors) {
            foreach ($rows as $row) {
                if (! in_array($row->status, $fromStatuses, true)) {
                    $errors[] = "Grade #{$row->id} cannot move from {$row->status} to {$toStatus}";

                    continue;
                }

                if ($row->registration_held) {
                    $errors[] = "Grade #{$row->id} is held until the student is enrolled for this course.";

                    continue;
                }

                $from = $row->status;
                $attrs = array_merge(['status' => $toStatus], $extra($row));
                $row->fill($attrs);
                $row->save();

                if ($viaStatus !== null) {
                    GradeAuditLogger::statusChange($row, $from, $viaStatus, $actor, $note);
                    GradeAuditLogger::statusChange($row, $viaStatus, $toStatus, $actor, $note);
                } else {
                    GradeAuditLogger::statusChange($row, $from, $toStatus, $actor, $note);
                }

                $updated++;
            }
        });

        return ['updated' => $updated, 'errors' => $errors];
    }

    private function upsertBoardScope(
        string $scopeType,
        ?int $facultyId,
        ?int $departmentId,
        int $academicTermId,
        string $status,
        Authenticatable $actor,
        ?string $note,
        bool $cleared = false,
        bool $corrections = false,
    ): void {
        if (! Schema::hasTable('grade_board_scopes')) {
            return;
        }

        $attrs = [
            'status' => $status,
            'note' => $note,
            'lists_generated_at' => now(),
            'acted_by_user_id' => $actor->getAuthIdentifier(),
        ];
        if ($cleared) {
            $attrs['cleared_at'] = now();
        }
        if ($corrections) {
            $attrs['corrections_requested_at'] = now();
        }

        GradeBoardScope::query()->updateOrCreate(
            [
                'scope_type' => $scopeType,
                'faculty_id' => $facultyId,
                'department_id' => $departmentId,
                'academic_term_id' => $academicTermId,
            ],
            $attrs,
        );
    }
}
