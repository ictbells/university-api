<?php

namespace App\Models;

use App\Models\BaseModel;


class WalletCredential extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
