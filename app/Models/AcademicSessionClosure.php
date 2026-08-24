<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicSessionClosure extends Model
{
    protected $fillable = [
        'academic_session_id',
        'trigger',
        'promoted_count',
        'skipped_final_count',
        'skipped_inactive_count',
        'skipped_no_program_count',
        'ran_at',
        'ran_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'promoted_count' => 'integer',
            'skipped_final_count' => 'integer',
            'skipped_inactive_count' => 'integer',
            'skipped_no_program_count' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function ranBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ran_by_user_id');
    }
}
