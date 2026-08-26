<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends BaseModel
{
    protected $fillable = ['course_id', 'academic_term_id', 'faculty_staff_id', 'lecturer_name', 'section', 'capacity'];

    protected $appends = ['lecturer_display_name'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function getLecturerDisplayNameAttribute(): ?string
    {
        $typed = trim((string) ($this->attributes['lecturer_name'] ?? ''));
        if ($typed !== '') {
            return $typed;
        }

        $fromStaff = $this->lecturer?->user?->name;

        return filled($fromStaff) ? (string) $fromStaff : null;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'faculty_staff_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function enrolledCount(): int
    {
        return $this->enrollments()->enrolled()->count();
    }

    public function hasUnlimitedCapacity(): bool
    {
        return $this->capacity === null;
    }

    public function seatsLeft(?int $enrolledCount = null): ?int
    {
        if ($this->hasUnlimitedCapacity()) {
            return null;
        }

        $taken = $enrolledCount ?? $this->enrolledCount();

        return max(0, (int) $this->capacity - $taken);
    }

    public function isFull(?int $enrolledCount = null): bool
    {
        if ($this->hasUnlimitedCapacity()) {
            return false;
        }

        $taken = $enrolledCount ?? $this->enrolledCount();

        return $taken >= (int) $this->capacity;
    }
}
