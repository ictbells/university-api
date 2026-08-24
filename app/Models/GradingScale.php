<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingScale extends Model
{
    protected $fillable = ['name', 'max_points', 'is_default'];

    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function boundaries(): HasMany
    {
        return $this->hasMany(GradeBoundary::class)->orderByDesc('min_score');
    }
}
