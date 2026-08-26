<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelRoom extends BaseModel
{
    protected $fillable = [
        'hostel_block_id',
        'number',
        'room_type',
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

    public const TYPE_STANDARD = 'standard';

    public const TYPE_STORE = 'store';

    public const TYPE_COMMON = 'common';

    public const TYPE_SUITE = 'suite';

    public const ROOM_TYPES = [
        self::TYPE_STANDARD,
        self::TYPE_STORE,
        self::TYPE_COMMON,
        self::TYPE_SUITE,
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

    public function isResidential(): bool
    {
        return $this->normalizedRoomType() !== self::TYPE_STORE;
    }

    public function normalizedRoomType(): string
    {
        $type = strtolower((string) ($this->room_type ?: self::TYPE_STANDARD));

        return in_array($type, self::ROOM_TYPES, true) ? $type : self::TYPE_STANDARD;
    }

    public static function roomTypeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_STORE => 'Store',
            self::TYPE_COMMON => 'Common room',
            self::TYPE_SUITE => 'Suite',
            default => 'Standard',
        };
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
