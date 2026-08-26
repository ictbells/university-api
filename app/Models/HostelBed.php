<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelBed extends BaseModel
{
    protected $fillable = ['hostel_room_id', 'label', 'bunk_position', 'bunk_pair', 'status'];

    public const POSITION_LOWER = 'lower';

    public const POSITION_UPPER = 'upper';

    public function displayLabel(): string
    {
        if ($this->bunk_position === self::POSITION_LOWER) {
            return 'Lower bunk'.($this->bunk_pair ? ' '.$this->bunk_pair : '');
        }
        if ($this->bunk_position === self::POSITION_UPPER) {
            return 'Upper bunk'.($this->bunk_pair ? ' '.$this->bunk_pair : '');
        }

        return 'Bed '.$this->label;
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id')->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class);
    }
}
