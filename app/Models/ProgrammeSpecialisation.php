<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgrammeSpecialisation extends BaseModel
{
    protected $fillable = [
        'program_id',
        'name',
        'code',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'specialisation_id');
    }
}
