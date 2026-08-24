<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AcademicSession extends BaseModel
{
    protected $fillable = [
        'label',
        'starts_on',
        'ends_on',
        'closed_at',
        'closed_by_user_id',
        'auto_close_on_end',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
            'auto_close_on_end' => 'boolean',
        ];
    }

    public function semesters(): HasMany
    {
        return $this->hasMany(AcademicTerm::class, 'academic_session_id')->orderBy('id');
    }

    public function closures(): HasMany
    {
        return $this->hasMany(AcademicSessionClosure::class, 'academic_session_id')->orderByDesc('id');
    }

    public function latestClosure(): HasOne
    {
        return $this->hasOne(AcademicSessionClosure::class, 'academic_session_id')->latestOfMany();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function isCurrent(): bool
    {
        return $this->semesters()->where('is_current', true)->exists();
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
