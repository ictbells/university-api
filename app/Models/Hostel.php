<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Hostel extends BaseModel
{
    public const CATEGORY_UNDERGRADUATE = 'undergraduate';

    public const CATEGORY_JUPEB = 'jupeb';

    protected $fillable = ['campus_id', 'name', 'gender', 'category', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(HostelBlock::class);
    }
}
