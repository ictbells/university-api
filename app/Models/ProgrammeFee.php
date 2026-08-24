<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammeFee extends BaseModel
{
    protected $fillable = [
        'program_id',
        'fee_item_id',
        'amount',
        'level_code',
        'semester',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }

    public function getEffectiveAmountAttribute(): float
    {
        return (float) ($this->amount ?? $this->feeItem?->amount ?? 0);
    }
}
