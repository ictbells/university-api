<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyWalletImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
