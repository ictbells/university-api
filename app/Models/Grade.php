<?php

namespace App\Models;

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
            'offering.course',
            'offering.term.session',
            'enrollment.student:id,first_name,last_name,matric_number,current_level',
            'enrollment.offering.course',
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

    public static function attachEnrollment(Enrollment $enrollment): int
    {
        return static::query()
            ->where('student_id', $enrollment->student_id)
            ->where('course_offering_id', $enrollment->course_offering_id)
            ->whereNull('enrollment_id')
            ->update(['enrollment_id' => $enrollment->id]);
    }
}
