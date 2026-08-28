<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Support\LevelProgression;
use App\Support\Studentship;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GraduationService
{
    public function __construct(private AuditWriter $audit) {}

    public function candidates(
        ?int $programId = null,
        ?int $campusId = null,
        ?string $search = null,
        int $perPage = 25,
        ?int $academicSessionId = null,
        ?string $level = null,
    ): LengthAwarePaginator
    {
        $query = $this->finalYearQuery()
            ->with(['program.department.faculty', 'user:id,name,email'])
            ->when($programId, fn (Builder $q) => $q->where('program_id', $programId))
            ->when($campusId, fn (Builder $q) => $q->whereHas(
                'program.department.faculty',
                fn (Builder $faculty) => $faculty->where('campus_id', $campusId),
            ))
            ->when($academicSessionId, fn (Builder $q) => $q->whereHas(
                'application.intake.term',
                fn (Builder $term) => $term->where('academic_session_id', $academicSessionId),
            ))
            ->when($level, fn (Builder $q) => $q->where('current_level', $level))
            ->when($search, function (Builder $q) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $q->where(function (Builder $builder) use ($term) {
                    $builder->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('matric_number', 'like', $term)
                        ->orWhere('student_number', 'like', $term);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name');

        $paginator = $query->paginate(min(100, max(10, $perPage)));
        $paginator->getCollection()->transform(fn (Student $student) => $this->decorate($student));

        return $paginator;
    }

    /**
     * @param  list<int>  $studentIds
     * @return array{conferred_count: int, skipped_count: int, students: list<Student>}
     */
    public function confer(array $studentIds, string $graduatedAt, ?int $academicSessionId = null, ?User $actor = null, bool $requireFinalYear = true): array
    {
        $date = Carbon::parse($graduatedAt)->startOfDay();
        $expires = Studentship::expiryDate($date);
        $ids = array_values(array_unique(array_map('intval', $studentIds)));
        if ($ids === []) {
            throw ValidationException::withMessages(['student_ids' => 'Select at least one student.']);
        }

        $conferred = [];
        $skipped = 0;

        DB::transaction(function () use ($ids, $date, $expires, $academicSessionId, $actor, $requireFinalYear, &$conferred, &$skipped) {
            $students = Student::query()->with('program')->whereIn('id', $ids)->lockForUpdate()->get();
            foreach ($students as $student) {
                if ($student->status !== Studentship::STATUS_ACTIVE) {
                    $skipped++;
                    continue;
                }
                if ($requireFinalYear && ! $this->isFinalYear($student)) {
                    $skipped++;
                    continue;
                }

                $before = $student->only(['status', 'graduated_at', 'studentship_expires_at']);
                $student->update([
                    'status' => Studentship::STATUS_GRADUATED,
                    'graduated_at' => $date->toDateString(),
                    'studentship_expires_at' => $expires->toDateString(),
                ]);
                $fresh = $student->fresh();
                $this->audit->record(
                    'student.graduated',
                    'Student conferred; studentship expiry scheduled',
                    'academic',
                    'student',
                    $student->id,
                    $before,
                    [
                        'status' => $fresh?->status,
                        'graduated_at' => $fresh?->graduated_at,
                        'studentship_expires_at' => $fresh?->studentship_expires_at,
                        'academic_session_id' => $academicSessionId,
                        'actor_id' => $actor?->id,
                    ],
                );
                $conferred[] = $this->decorate($fresh ?? $student);
            }
        });

        return [
            'conferred_count' => count($conferred),
            'skipped_count' => $skipped,
            'graduated_at' => $date->toDateString(),
            'studentship_expires_at' => $expires->toDateString(),
            'students' => $conferred,
        ];
    }

    public function expireDue(?Carbon $today = null): int
    {
        $today ??= now()->startOfDay();
        $count = 0;
        Student::query()
            ->where('status', Studentship::STATUS_GRADUATED)
            ->whereNotNull('studentship_expires_at')
            ->whereDate('studentship_expires_at', '<=', $today)
            ->orderBy('id')
            ->chunkById(200, function ($students) use (&$count) {
                foreach ($students as $student) {
                    $before = $student->only(['status']);
                    Studentship::expire($student);
                    $this->audit->record(
                        'student.studentship_expired',
                        'Studentship ended two years after graduation',
                        'academic',
                        'student',
                        $student->id,
                        $before,
                        ['status' => Studentship::STATUS_ALUMNI],
                    );
                    $count++;
                }
            });

        return $count;
    }

    private function finalYearQuery(): Builder
    {
        return Student::query()
            ->where('status', Studentship::STATUS_ACTIVE)
            ->whereHas('program')
            ->where(function (Builder $query) {
                $query->whereHas('program', function (Builder $program) {
                    $program->where('study_level', 'postgraduate')
                        ->whereColumn('students.current_level', '>=', 'programs.duration_years');
                })->orWhereHas('program', function (Builder $program) {
                    $program->where('study_level', '!=', 'postgraduate')
                        ->whereRaw('students.current_level >= programs.duration_years * 100');
                });
            });
    }

    private function isFinalYear(Student $student): bool
    {
        return LevelProgression::isFinalYear($student);
    }

    private function decorate(Student $student): Student
    {
        $program = $student->program;
        $student->setAttribute('final_level', $program ? LevelProgression::finalLevelForProgram($program) : null);
        $student->setAttribute('studentship_current', Studentship::isCurrent($student));

        return $student;
    }
}
