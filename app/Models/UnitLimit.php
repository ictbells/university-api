<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitLimit extends BaseModel
{
    public const BUCKETS = ['general', 'faculty', 'departmental', 'overall'];

    protected $fillable = [
        'program_id',
        'academic_level_id',
        'academic_term_id',
        'bucket',
        'min_units',
        'max_units',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }
}
