<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends BaseModel
{
    protected $fillable = ['department_id', 'name', 'code', 'award_type', 'study_level', 'entry_modes', 'duration_years', 'tuition_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'entry_modes' => 'array',
            'is_active' => 'boolean',
            'tuition_amount' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_course')
            ->withPivot(['academic_level_id', 'bucket'])
            ->withTimestamps();
    }

    public function programmeFees(): HasMany
    {
        return $this->hasMany(ProgrammeFee::class);
    }
}
