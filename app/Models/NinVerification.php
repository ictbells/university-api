<?php

namespace App\Models;

use App\Support\NinCipher;

class NinVerification extends BaseModel
{
    protected $guarded = [];

    protected $hidden = ['nin_hash'];

    protected function casts(): array
    {
        return [
            'mapped_fields' => 'array',
            'raw_snapshot' => 'array',
            'verified_at' => 'datetime',
            'nin' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (NinVerification $record) {
            $plain = is_string($record->nin) ? NinCipher::normalize($record->nin) : '';
            $record->nin_hash = $plain !== '' ? NinCipher::hash($plain) : null;
        });
    }
}
