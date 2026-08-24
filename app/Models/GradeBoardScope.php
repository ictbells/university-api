<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeBoardScope extends Model
{
    protected $fillable = [
        'scope_type',
        'faculty_id',
        'department_id',
        'academic_term_id',
        'status',
        'note',
        'lists_generated_at',
        'cleared_at',
        'corrections_requested_at',
        'acted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'lists_generated_at' => 'datetime',
            'cleared_at' => 'datetime',
            'corrections_requested_at' => 'datetime',
        ];
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }
}
