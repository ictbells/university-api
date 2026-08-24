<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class RebateType extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function rebates(): HasMany
    {
        return $this->hasMany(InvoiceRebate::class);
    }
}
