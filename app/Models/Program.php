<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Program extends BaseModel
{
    protected $fillable = ['department_id', 'name', 'code', 'award_type', 'study_level', 'entry_modes', 'duration_years', 'is_active'];

    protected function casts(): array
    {
        return [
            'entry_modes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_course')->withTimestamps();
    }
}
