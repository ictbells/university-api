<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelBed extends BaseModel
{
    protected $fillable = ['hostel_room_id', 'label', 'status'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id')->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class);
    }
}
