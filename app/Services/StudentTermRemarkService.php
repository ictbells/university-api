<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentTermRemark;
use App\Models\User;
use App\Support\GradeExamRemark;
use Illuminate\Validation\ValidationException;

class StudentTermRemarkService
{
    /**
     * @return array<int, string> student_id => type
     */
    public static function typesForStudents(array $studentIds, int $academicTermId): array
    {
        $studentIds = array_values(array_unique(array_filter($studentIds)));
        if ($studentIds === [] || $academicTermId <= 0) {
            return [];
        }

        return StudentTermRemark::query()
            ->where('academic_term_id', $academicTermId)
            ->whereIn('student_id', $studentIds)
            ->pluck('type', 'student_id')
            ->map(fn ($type) => (string) $type)
            ->all();
    }

    /**
     * @return list<int>
     */
    public static function studentIdsForTerm(int $academicTermId): array
    {
        if ($academicTermId <= 0) {
            return [];
        }

        return StudentTermRemark::query()
            ->where('academic_term_id', $academicTermId)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function apply(Student $student, int $academicTermId, string $type, ?string $note, User $actor): StudentTermRemark
    {
        $normalized = GradeExamRemark::normalize($type);
        if ($normalized === null || ! in_array($normalized, GradeExamRemark::adminTypes(), true)) {
            throw ValidationException::withMessages(['type' => 'Use ABS_P, ABS_NP, or SICK. Registered courses with no score are AR automatically.']);
        }

        $row = StudentTermRemark::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_term_id' => $academicTermId,
            ],
            [
                'type' => $normalized,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'recorded_by' => $actor->id,
            ],
        );

        return $row->fresh() ?? $row;
    }

    public function lift(StudentTermRemark $remark): void
    {
        $remark->delete();
    }
}
