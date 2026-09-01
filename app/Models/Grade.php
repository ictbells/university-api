<?php

namespace App\Models;

use App\Support\GradeExamRemark;
use App\Support\GradeLetterResolver;
use App\Support\GradeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends BaseModel
{
    protected $fillable = [
        'enrollment_id',
        'student_id',
        'course_offering_id',
        'sitting',
        'letter',
        'exam_remark',
        'points',
        'score',
        'ca_score',
        'exam_score',
        'status',
        'source',
        'source_ref',
        'upload_lane',
        'faculty_id',
        'department_id',
        'entered_by',
        'submitted_at',
        'faculty_approved_at',
        'board_cleared_at',
        'released_at',
        'correction_note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'score' => 'decimal:2',
            'ca_score' => 'decimal:2',
            'exam_score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'faculty_approved_at' => 'datetime',
            'board_cleared_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected $appends = ['registration_held'];

    protected static function booted(): void
    {
        static::saving(function (Grade $grade) {
            if ($grade->enrollment_id && (! $grade->student_id || ! $grade->course_offering_id)) {
                $enrollment = $grade->relationLoaded('enrollment')
                    ? $grade->enrollment
                    : Enrollment::query()->find($grade->enrollment_id);
                if ($enrollment) {
                    $grade->student_id = $grade->student_id ?: $enrollment->student_id;
                    $grade->course_offering_id = $grade->course_offering_id ?: $enrollment->course_offering_id;
                }
            }
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(GradeStatusEvent::class);
    }

    public function isEditable(): bool
    {
        return GradeStatus::isEditable((string) $this->status);
    }

    public function isReleased(): bool
    {
        return GradeStatus::isReleased((string) $this->status);
    }

    public function resolvedStudentId(): int
    {
        return (int) ($this->student_id ?: $this->enrollment?->student_id ?: 0);
    }

    public function resolvedOffering(): ?CourseOffering
    {
        return $this->offering ?? $this->enrollment?->offering;
    }

    public function resolvedStudent(): ?Student
    {
        return $this->student ?? $this->enrollment?->student;
    }

    public function courseUnits(): int
    {
        return (int) ($this->resolvedOffering()?->course?->units ?? 0);
    }

    public function hasExamRemark(): bool
    {
        return GradeExamRemark::normalize($this->exam_remark) !== null;
    }

    public function examRemarkCode(): ?string
    {
        return GradeExamRemark::normalize($this->exam_remark);
    }

    public function resolvedLetter(): string
    {
        if ($this->hasExamRemark()) {
            return '';
        }
        $letter = strtoupper(trim((string) ($this->letter ?? '')));
        if ($letter !== '') {
            return $letter;
        }
        if ($this->score === null || $this->score === '') {
            return '';
        }
        $resolved = GradeLetterResolver::fromScore((float) $this->score);

        return strtoupper(trim((string) ($resolved['letter'] ?? '')));
    }

    public function resolvedGradePoints(): float
    {
        $letter = $this->resolvedLetter();
        if ($letter === 'F') {
            return 0.0;
        }
        if ($this->points !== null && (float) $this->points > 0) {
            return (float) $this->points;
        }
        if ($letter !== '') {
            $fromLetter = GradeLetterResolver::gradePointForLetter($letter);
            if ($fromLetter !== null) {
                return (float) $fromLetter;
            }
        }
        if ($this->score !== null && $this->score !== '') {
            $resolved = GradeLetterResolver::fromScore((float) $this->score);
            if ($resolved !== null) {
                return (float) $resolved['grade_point'];
            }
        }

        return (float) ($this->points ?? 0);
    }

    public function getRegistrationHeldAttribute(): bool
    {
        if (! $this->enrollment_id) {
            return true;
        }

        $enrollment = $this->relationLoaded('enrollment')
            ? $this->enrollment
            : $this->enrollment()->first();

        return ! $enrollment || $enrollment->status !== 'enrolled';
    }

    public function scopeWithResolved(Builder $query): Builder
    {
        return $query->with([
            'student:id,first_name,last_name,matric_number,current_level',
            'offering.course.department',
            'offering.term.session',
            'enrollment.student:id,first_name,last_name,matric_number,current_level',
            'enrollment.offering.course.department',
            'enrollment.offering.term.session',
        ]);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where(function (Builder $q) use ($studentId) {
            $q->where('student_id', $studentId)
                ->orWhereHas('enrollment', fn (Builder $e) => $e->where('student_id', $studentId));
        });
    }

    public function scopeForTerm(Builder $query, int $termId): Builder
    {
        return $query->where(function (Builder $q) use ($termId) {
            $q->whereHas('offering', fn (Builder $o) => $o->where('academic_term_id', $termId))
                ->orWhereHas('enrollment.offering', fn (Builder $o) => $o->where('academic_term_id', $termId));
        });
    }

    public function scopeForDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where(function (Builder $q) use ($departmentId) {
            $q->where('department_id', $departmentId)
                ->orWhereHas('offering.course', fn (Builder $c) => $c->where('department_id', $departmentId))
                ->orWhereHas('enrollment.offering.course', fn (Builder $c) => $c->where('department_id', $departmentId))
                ->orWhereHas('student.program', fn (Builder $p) => $p->where('department_id', $departmentId))
                ->orWhereHas('enrollment.student.program', fn (Builder $p) => $p->where('department_id', $departmentId));
        });
    }

    public function scopeForFaculty(Builder $query, int $facultyId, bool $includeGeneralLane = false): Builder
    {
        return $query->where(function (Builder $q) use ($facultyId, $includeGeneralLane) {
            $q->where('faculty_id', $facultyId)
                ->orWhereHas('offering.course.department', fn (Builder $d) => $d->where('faculty_id', $facultyId))
                ->orWhereHas('enrollment.offering.course.department', fn (Builder $d) => $d->where('faculty_id', $facultyId))
                ->orWhereHas('student.program.department', fn (Builder $d) => $d->where('faculty_id', $facultyId))
                ->orWhereHas('enrollment.student.program.department', fn (Builder $d) => $d->where('faculty_id', $facultyId));
            if ($includeGeneralLane) {
                $q->orWhere('upload_lane', GradeStatus::LANE_GENERAL);
            }
        });
    }

    public function scopeForSession(Builder $query, ?int $sessionId): Builder
    {
        if (! $sessionId) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($sessionId) {
            $q->whereHas('offering.term', fn (Builder $t) => $t->where('academic_session_id', $sessionId))
                ->orWhereHas('enrollment.offering.term', fn (Builder $t) => $t->where('academic_session_id', $sessionId));
        });
    }

    public function scopeForLevel(Builder $query, ?string $level): Builder
    {
        $raw = trim((string) $level);
        if ($raw === '') {
            return $query;
        }

        $ids = AcademicLevel::idsMatching($raw);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($ids) {
            $q->whereHas(
                'offering.course.programs',
                fn (Builder $p) => $p->whereIn('program_course.academic_level_id', $ids)
            )->orWhereHas(
                'enrollment.offering.course.programs',
                fn (Builder $p) => $p->whereIn('program_course.academic_level_id', $ids)
            );
        });
    }

    /**
     * @return list<string>
     */
    public static function levelMatchValues(?string $level): array
    {
        $raw = trim((string) $level);
        if ($raw === '') {
            return [];
        }

        $digits = preg_match('/(\d{2,3})/', $raw, $match) ? $match[1] : null;
        $values = array_filter([
            $raw,
            $digits,
            $digits ? $digits.'L' : null,
            $digits ? $digits.' Level' : null,
        ]);

        $catalog = AcademicLevel::query()
            ->where(function (Builder $q) use ($raw, $digits) {
                $q->where('code', $raw)->orWhere('name', $raw);
                if ($digits) {
                    $q->orWhere('code', $digits)
                        ->orWhere('code', $digits.'L')
                        ->orWhere('name', 'like', $digits.'%');
                }
            })
            ->get(['code', 'name']);

        foreach ($catalog as $row) {
            $values[] = (string) $row->code;
            $values[] = (string) $row->name;
            if (preg_match('/(\d{2,3})/', (string) $row->code.(string) $row->name, $match)) {
                $values[] = $match[1];
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $values))));
    }

    public static function attachEnrollment(Enrollment $enrollment): int
    {
        return static::query()
            ->where('student_id', $enrollment->student_id)
            ->where('course_offering_id', $enrollment->course_offering_id)
            ->whereNull('enrollment_id')
            ->update(['enrollment_id' => $enrollment->id]);
    }
}
