<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelBlock extends BaseModel
{
    protected $fillable = ['hostel_id', 'name'];

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class)->withTrashed();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class);
    }
}
