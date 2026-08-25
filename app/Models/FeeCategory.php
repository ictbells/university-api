<?php

namespace App\Models;

class FeeCategory extends BaseModel
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_schedule',
        'is_system',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_schedule' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeStaffEditable($query)
    {
        return $query;
    }
}
