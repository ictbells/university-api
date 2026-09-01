<?php

namespace App\Support;

use App\Models\CourseOffering;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use App\Services\GradeWorkflowService;
use Illuminate\Database\Eloquent\Builder;

final class ResultOfficerScope
{
    public const ROLE_EXAM_OFFICER = 'exam-officer';

    public const ROLE_FACULTY = 'faculty-exam-officer';

    public const ROLE_DEPARTMENT = 'department-exam-officer';

    public const ROLE_GS = 'gs-exam-officer';

    /**
     * @return array{global: bool, faculty_ids: list<int>, department_ids: list<int>, kind: string}
     */
    public static function for(User $actor): array
    {
        $kind = self::kind($actor);
        if ($kind === 'global') {
            return ['global' => true, 'faculty_ids' => [], 'department_ids' => [], 'kind' => 'global'];
        }

        $staff = $actor->relationLoaded('staff')
            ? $actor->staff
            : $actor->staff()->with('department')->first();
        $staff?->loadMissing('department');

        $departmentId = $staff?->department_id ? (int) $staff->department_id : null;
        $facultyId = $staff?->department?->faculty_id ? (int) $staff->department->faculty_id : null;

        if ($kind === 'faculty') {
            return [
                'global' => false,
                'faculty_ids' => $facultyId ? [$facultyId] : [],
                'department_ids' => [],
                'kind' => 'faculty',
            ];
        }

        if ($kind === 'gs') {
            return ['global' => false, 'faculty_ids' => [], 'department_ids' => [], 'kind' => 'gs'];
        }

        return [
            'global' => false,
            'faculty_ids' => [],
            'department_ids' => $departmentId ? [$departmentId] : [],
            'kind' => 'department',
        ];
    }

    public static function kind(User $actor): string
    {
        $slugs = $actor->roles()->where('is_active', true)->pluck('slug')->all();

        if (array_intersect($slugs, ['super-admin', 'registrar', self::ROLE_EXAM_OFFICER]) !== []) {
            return 'global';
        }
        if (in_array(self::ROLE_FACULTY, $slugs, true)) {
            return 'faculty';
        }
        if (in_array(self::ROLE_GS, $slugs, true)) {
            return 'gs';
        }
        if (in_array(self::ROLE_DEPARTMENT, $slugs, true)) {
            return 'department';
        }

        return 'global';
    }

    /**
     * @param  array{upload_lane?: ?string, faculty_id?: ?int, department_id?: ?int}  $org
     */
    public static function assertLaneAccess(User $actor, array $org): void
    {
        $kind = self::kind($actor);
        $lane = $org['upload_lane'] ?? null;

        if ($kind === 'global') {
            return;
        }

        if ($kind === 'department') {
            if ($lane !== GradeStatus::LANE_DEPARTMENTAL
                || ! self::canAccessDepartment($actor, isset($org['department_id']) ? (int) $org['department_id'] : null)) {
                abort(403, 'Outside department upload scope.');
            }

            return;
        }

        if ($kind === 'gs') {
            if ($lane !== GradeStatus::LANE_GENERAL) {
                abort(403, 'GS officers may only upload general courses.');
            }

            return;
        }

        if ($kind === 'faculty') {
            if ($lane !== GradeStatus::LANE_FACULTY
                || ! self::canAccessFaculty($actor, isset($org['faculty_id']) ? (int) $org['faculty_id'] : null)) {
                abort(403, 'Outside faculty upload scope.');
            }
        }
    }

    public static function assertCanMutate(User $actor, Grade $grade): void
    {
        self::assertLaneAccess($actor, [
            'upload_lane' => $grade->upload_lane,
            'faculty_id' => $grade->faculty_id ? (int) $grade->faculty_id : null,
            'department_id' => $grade->department_id ? (int) $grade->department_id : null,
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    public static function assertCanActOnGrades(User $actor, array $ids): void
    {
        $scope = self::for($actor);
        if ($scope['global'] || $ids === []) {
            return;
        }

        $rows = Grade::query()->whereIn('id', $ids)->get(['id', 'faculty_id', 'department_id', 'upload_lane']);
        foreach ($rows as $row) {
            if (! self::gradeInScope($row, $scope)) {
                abort(403, "Grade #{$row->id} is outside your upload scope.");
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public static function assertFacultyApprove(User $actor, array $ids): void
    {
        $scope = self::for($actor);
        if ($scope['global']) {
            return;
        }

        $rows = Grade::query()->whereIn('id', $ids)->get(['id', 'faculty_id', 'upload_lane']);
        foreach ($rows as $row) {
            if ($row->upload_lane === GradeStatus::LANE_GENERAL) {
                continue;
            }
            if (! in_array((int) $row->faculty_id, $scope['faculty_ids'], true)) {
                abort(403, "Grade #{$row->id} is outside your faculty scope.");
            }
        }
    }

    public static function constrainGrades(Builder $query, User $actor): Builder
    {
        $scope = self::for($actor);
        if ($scope['global']) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($scope) {
            if ($scope['kind'] === 'gs') {
                $q->where('upload_lane', GradeStatus::LANE_GENERAL);

                return;
            }
            if ($scope['faculty_ids'] !== []) {
                $q->orWhereIn('faculty_id', $scope['faculty_ids']);
            }
            if ($scope['department_ids'] !== []) {
                $q->orWhereIn('department_id', $scope['department_ids']);
            }
            if ($scope['faculty_ids'] === [] && $scope['department_ids'] === []) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public static function constrainOfferings(Builder $query, User $actor): Builder
    {
        $scope = self::for($actor);
        if ($scope['global']) {
            return $query;
        }

        return $query->whereHas('course', function (Builder $q) use ($scope) {
            if ($scope['kind'] === 'gs') {
                $q->whereIn('course_type', ['general', 'general_studies', 'gst']);

                return;
            }
            if ($scope['kind'] === 'department') {
                $ids = $scope['department_ids'] !== [] ? $scope['department_ids'] : [0];
                $q->where('course_type', GradeStatus::LANE_DEPARTMENTAL)
                    ->whereIn('department_id', $ids);

                return;
            }
            if ($scope['kind'] === 'faculty') {
                $ids = $scope['faculty_ids'] !== [] ? $scope['faculty_ids'] : [0];
                $q->where('course_type', GradeStatus::LANE_FACULTY)
                    ->whereHas('department', fn (Builder $d) => $d->whereIn('faculty_id', $ids));
            }
        });
    }

    public static function assertOfferingAccess(User $actor, CourseOffering $offering): void
    {
        $offering->loadMissing('course.department');
        $org = GradeWorkflowService::orgSnapshotFromCourse($offering->course);
        self::assertLaneAccess($actor, $org);
    }

    /**
     * @param  array{global: bool, faculty_ids: list<int>, department_ids: list<int>, kind: string}  $scope
     */
    private static function gradeInScope(Grade $row, array $scope): bool
    {
        if ($scope['kind'] === 'gs') {
            return $row->upload_lane === GradeStatus::LANE_GENERAL;
        }
        if ($scope['faculty_ids'] !== [] && in_array((int) $row->faculty_id, $scope['faculty_ids'], true)) {
            return true;
        }
        if ($scope['department_ids'] !== [] && in_array((int) $row->department_id, $scope['department_ids'], true)) {
            return true;
        }

        return false;
    }

    public static function assertStudentInScope(User $actor, Student $student): void
    {
        $scope = self::for($actor);
        if ($scope['global'] || $scope['kind'] === 'gs') {
            return;
        }

        $student->loadMissing('program.department');
        $departmentId = (int) ($student->program?->department_id ?? 0);
        $facultyId = (int) ($student->program?->department?->faculty_id ?? 0);
        if ($scope['department_ids'] !== [] && in_array($departmentId, $scope['department_ids'], true)) {
            return;
        }
        if ($scope['faculty_ids'] !== [] && in_array($facultyId, $scope['faculty_ids'], true)) {
            return;
        }

        abort(403, 'Outside your results scope.');
    }

    public static function canAccessFaculty(User $actor, ?int $facultyId): bool
    {
        $scope = self::for($actor);
        if ($scope['global']) {
            return true;
        }
        if ($facultyId === null) {
            return false;
        }

        return in_array($facultyId, $scope['faculty_ids'], true);
    }

    public static function canAccessDepartment(User $actor, ?int $departmentId): bool
    {
        $scope = self::for($actor);
        if ($scope['global']) {
            return true;
        }
        if ($departmentId === null) {
            return false;
        }

        return in_array($departmentId, $scope['department_ids'], true);
    }
}
