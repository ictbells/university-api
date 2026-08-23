<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelRoom extends BaseModel
{
    protected $fillable = [
        'hostel_block_id',
        'number',
        'capacity',
        'gender',
        'is_active',
        'is_reserved',
        'reserve_note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_reserved' => 'boolean',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(HostelBlock::class, 'hostel_block_id');
    }

    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class);
    }
}
