<?php

namespace App\Models;

class FeeItem extends BaseModel
{
    protected $fillable = ['name', 'description', 'category', 'entry_mode', 'amount', 'wallet_allowed', 'is_required', 'display_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'wallet_allowed' => 'boolean',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'amount' => 'decimal:2',
            'display_order' => 'integer',
        ];
    }
}
