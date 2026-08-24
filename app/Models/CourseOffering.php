<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends BaseModel
{
    protected $fillable = ['course_id', 'academic_term_id', 'faculty_staff_id', 'section', 'capacity'];

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

    public function seatsLeft(): int
    {
        return max(0, (int) $this->capacity - $this->enrolledCount());
    }
}
