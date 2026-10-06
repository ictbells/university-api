<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends BaseModel
{
    public const TYPES = ['general', 'faculty', 'departmental'];

    public const STATUSES = ['core', 'elective', 'required'];

    protected $fillable = ['department_id', 'code', 'code_key', 'title', 'units', 'course_type', 'status'];

    protected static function booted(): void
    {
        static::saving(function (Course $course) {
            if ($course->isDirty('code') || blank($course->code_key)) {
                $course->code_key = self::normalizeCode((string) $course->code);
            }
        });
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'elective' => 'Elective',
            'required' => 'Required',
            default => 'Core',
        };
    }

    /** Compare codes ignoring case and spaces (CSC201 = CSC 201 = csc201). */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(str_replace(' ', '', trim($code)));
    }

    public static function findByNormalizedCode(string $code, ?int $ignoreId = null): ?self
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === '') {
            return null;
        }

        $query = static::query()->where('code_key', $normalized);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $match = $query->first();
        if ($match) {
            return $match;
        }

        // Fallback for rows not yet backfilled (pre-migration / mid-deploy).
        $legacy = static::query()
            ->whereRaw("UPPER(REPLACE(COALESCE(code, ''), ' ', '')) = ?", [$normalized]);
        if ($ignoreId !== null) {
            $legacy->where('id', '!=', $ignoreId);
        }

        return $legacy->first();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'program_course')
            ->withPivot(['academic_level_id', 'bucket'])
            ->withTimestamps();
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }
}
