<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelRoom extends BaseModel
{
    protected $fillable = [
        'hostel_block_id',
        'number',
        'capacity',
        'bedding_type',
        'gender',
        'is_active',
        'is_reserved',
        'reserve_note',
    ];

    public const BEDDING_SINGLE = 'single';

    public const BEDDING_BUNK = 'bunk';

    public const BEDDING_TYPES = [
        self::BEDDING_SINGLE,
        self::BEDDING_BUNK,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_reserved' => 'boolean',
        ];
    }

    public function usesBunks(): bool
    {
        return $this->bedding_type === self::BEDDING_BUNK;
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(HostelBlock::class, 'hostel_block_id')->withTrashed();
    }

    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class);
    }
}
