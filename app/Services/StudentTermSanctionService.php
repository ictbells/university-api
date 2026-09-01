<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentTermSanction;
use App\Models\User;
use App\Support\Studentship;
use App\Support\StudentTermSanctionType;
use Illuminate\Validation\ValidationException;

class StudentTermSanctionService
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

        return StudentTermSanction::query()
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

        return StudentTermSanction::query()
            ->where('academic_term_id', $academicTermId)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function apply(Student $student, int $academicTermId, string $type, ?string $note, User $actor): StudentTermSanction
    {
        if (! in_array($type, StudentTermSanctionType::all(), true)) {
            throw ValidationException::withMessages(['type' => 'Choose rusticated, expelled, suspended, or withdrawn.']);
        }

        $row = StudentTermSanction::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_term_id' => $academicTermId,
            ],
            [
                'type' => $type,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'recorded_by' => $actor->id,
            ],
        );

        $status = StudentTermSanctionType::studentshipStatus($type);
        if ($student->status !== $status) {
            $student->update(['status' => $status]);
        }

        return $row->fresh() ?? $row;
    }

    public function lift(StudentTermSanction $sanction): void
    {
        $student = $sanction->student;
        $type = $sanction->type;
        $sanction->delete();

        if ($student && $student->status === StudentTermSanctionType::studentshipStatus($type)) {
            $still = StudentTermSanction::query()
                ->where('student_id', $student->id)
                ->orderByDesc('id')
                ->first();
            $student->update([
                'status' => $still
                    ? StudentTermSanctionType::studentshipStatus($still->type)
                    : Studentship::STATUS_ACTIVE,
            ]);
        }
    }
}
