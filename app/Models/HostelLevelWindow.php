<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostelLevelWindow extends BaseModel
{
    protected $fillable = [
        'category',
        'academic_level_id',
        'academic_term_id',
        'is_active',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
