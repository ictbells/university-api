<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeStatusEvent extends BaseModel
{
    protected $fillable = [
        'grade_id',
        'action',
        'student_id',
        'course_id',
        'academic_term_id',
        'sitting',
        'from_status',
        'to_status',
        'note',
        'meta',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
