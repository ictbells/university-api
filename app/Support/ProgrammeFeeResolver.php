<?php

namespace App\Support;

use App\Models\ProgrammeFee;
use App\Models\Student;
use Illuminate\Support\Collection;

class ProgrammeFeeResolver
{
    /**
     * @return Collection<int, ProgrammeFee>
     */
    public static function forProgram(
        int $programId,
        ?string $levelCode = null,
        ?string $semester = null,
    ): Collection {
        $query = ProgrammeFee::query()
            ->with('feeItem')
            ->where('program_id', $programId)
            ->where('is_active', true)
            ->whereHas('feeItem', fn ($fee) => $fee->where('is_active', true));

        if ($levelCode !== null && $levelCode !== '') {
            $query->where(function ($builder) use ($levelCode) {
                $builder->where('level_code', 'all')
                    ->orWhere('level_code', $levelCode);
            });
        }

        if ($semester !== null && $semester !== '' && $semester !== 'both') {
            $query->where(function ($builder) use ($semester) {
                $builder->where('semester', $semester)
                    ->orWhere('semester', 'both');
            });
        }

        return $query->orderBy('display_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, ProgrammeFee>
     */
    public static function forStudent(Student $student, ?string $semester = null): Collection
    {
        $student->loadMissing('program');
        if (! $student->program_id) {
            return collect();
        }

        $levelCode = $student->current_level !== null
            ? (string) $student->current_level
            : null;

        return self::forProgram((int) $student->program_id, $levelCode, $semester);
    }

    public static function totalForProgram(
        int $programId,
        ?string $levelCode = null,
        ?string $semester = null,
    ): float {
        return round((float) self::forProgram($programId, $levelCode, $semester)->sum(
            fn (ProgrammeFee $fee) => $fee->effective_amount
        ), 2);
    }

    public static function totalForStudent(Student $student, ?string $semester = null): float
    {
        return round((float) self::forStudent($student, $semester)->sum(
            fn (ProgrammeFee $fee) => $fee->effective_amount
        ), 2);
    }
}
