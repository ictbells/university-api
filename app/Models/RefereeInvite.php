<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefereeInvite extends BaseModel
{
    protected $fillable = [
        'application_id', 'position', 'name', 'email', 'institution', 'position_title',
        'phone', 'token_hash', 'expires_at', 'status', 'submitted_at', 'comments',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isExpired(): bool
    {
        return $this->status !== 'submitted' && $this->expires_at?->isPast();
    }
}
