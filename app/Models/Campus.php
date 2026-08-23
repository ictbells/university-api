<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Campus extends BaseModel
{
    protected $fillable = ['name', 'code', 'city', 'address', 'is_active'];

    public function faculties(): HasMany
    {
        return $this->hasMany(Faculty::class);
    }
}
