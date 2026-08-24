<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends BaseModel
{
    protected $fillable = [
        'department_id', 'name', 'code', 'award_type', 'study_level', 'entry_modes',
        'duration_years', 'tuition_amount', 'is_active', 'is_research_degree',
        'eligibility', 'workflow_template_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_modes' => 'array',
            'eligibility' => 'array',
            'is_active' => 'boolean',
            'is_research_degree' => 'boolean',
            'tuition_amount' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
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
