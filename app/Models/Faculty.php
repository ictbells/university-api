<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends BaseModel
{
    protected $fillable = ['campus_id', 'name', 'code'];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
