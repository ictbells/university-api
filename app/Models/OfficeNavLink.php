<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class OfficeNavLink extends BaseModel
{
    protected $fillable = ['nav_key'];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
