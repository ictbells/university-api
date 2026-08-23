<?php

namespace App\Models;

use App\Models\BaseModel;


class FeeItem extends BaseModel
{
    protected $fillable = ['name', 'category', 'entry_mode', 'amount', 'wallet_allowed', 'is_active'];

    protected function casts(): array
    {
        return ['wallet_allowed' => 'boolean', 'is_active' => 'boolean', 'amount' => 'decimal:2'];
    }
}
