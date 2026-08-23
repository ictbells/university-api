<?php

namespace App\Models;

use App\Models\BaseModel;


class NinVerification extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mapped_fields' => 'array',
            'raw_snapshot' => 'array',
            'verified_at' => 'datetime',
        ];
    }
}
