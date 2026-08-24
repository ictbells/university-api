<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeBoundary extends BaseModel
{
    protected $fillable = [
        'grading_scale_id',
        'letter',
        'min_score',
        'max_score',
        'grade_point',
    ];

    protected function casts(): array
    {
        return [
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'grade_point' => 'decimal:2',
        ];
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'grading_scale_id');
    }
}
