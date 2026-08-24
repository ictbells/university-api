<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends BaseModel
{
    public const TYPES = ['general', 'faculty', 'departmental'];

    protected $fillable = ['department_id', 'code', 'title', 'units', 'course_type'];

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
