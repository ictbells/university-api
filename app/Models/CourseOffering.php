<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends BaseModel
{
    protected $fillable = ['course_id', 'academic_term_id', 'faculty_staff_id', 'lecturer_name', 'section', 'capacity'];

    protected $appends = ['lecturer_display_name', 'faculty_staff_ids'];

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

        $names = $this->lecturerNamesFromStaff();
        if ($names !== []) {
            return implode(', ', $names);
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function getFacultyStaffIdsAttribute(): array
    {
        if ($this->relationLoaded('lecturers')) {
            return $this->lecturers->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        $ids = $this->lecturers()->pluck('staff.id')->map(fn ($id) => (int) $id)->all();
        if ($ids !== []) {
            return $ids;
        }

        $legacy = (int) ($this->attributes['faculty_staff_id'] ?? 0);

        return $legacy > 0 ? [$legacy] : [];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** Primary/legacy single lecturer (first assigned). Prefer lecturers(). */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'faculty_staff_id');
    }

    public function lecturers(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'course_offering_staff')
            ->withTimestamps()
            ->orderBy('staff.id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scopeForStaff(Builder $query, int $staffId): Builder
    {
        return $query->where(function (Builder $q) use ($staffId) {
            $q->where('faculty_staff_id', $staffId)
                ->orWhereHas('lecturers', fn (Builder $staff) => $staff->where('staff.id', $staffId));
        });
    }

    public function isAssignedToStaff(int $staffId): bool
    {
        if ($staffId <= 0) {
            return false;
        }
        if ((int) ($this->attributes['faculty_staff_id'] ?? 0) === $staffId) {
            return true;
        }
        if ($this->relationLoaded('lecturers')) {
            return $this->lecturers->contains(fn (Staff $staff) => (int) $staff->id === $staffId);
        }

        return $this->lecturers()->where('staff.id', $staffId)->exists();
    }

    /**
     * @param  list<int>|null  $staffIds
     */
    public function syncLecturers(?array $staffIds): void
    {
        if ($staffIds === null) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => (int) $id,
            $staffIds,
        ), fn ($id) => $id > 0)));

        $this->lecturers()->sync($ids);
        $this->forceFill(['faculty_staff_id' => $ids[0] ?? null])->saveQuietly();
        $this->unsetRelation('lecturers');
        $this->unsetRelation('lecturer');
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

    /**
     * @return list<string>
     */
    private function lecturerNamesFromStaff(): array
    {
        $staffRows = $this->relationLoaded('lecturers')
            ? $this->lecturers
            : $this->lecturers()->with('user:id,name')->get();

        if ($staffRows->isEmpty() && $this->faculty_staff_id) {
            $this->loadMissing('lecturer.user');
            $name = $this->lecturer?->user?->name;

            return filled($name) ? [(string) $name] : [];
        }

        return $staffRows
            ->map(function (Staff $staff) {
                if (! $staff->relationLoaded('user')) {
                    $staff->loadMissing('user:id,name');
                }

                return trim((string) ($staff->user?->name ?? ''));
            })
            ->filter()
            ->values()
            ->all();
    }
}
