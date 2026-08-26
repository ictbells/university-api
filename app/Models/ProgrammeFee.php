<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammeFee extends BaseModel
{
    protected $fillable = [
        'program_id',
        'fee_item_id',
        'amount',
        'installment_tranche',
        'level_code',
        'semester',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'installment_tranche' => 'integer',
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

    public function getEffectiveInstallmentTrancheAttribute(): ?int
    {
        $override = $this->attributes['installment_tranche'] ?? null;
        if ($override !== null && $override !== '') {
            return (int) $override;
        }
        $catalog = $this->feeItem?->installment_tranche;

        return $catalog !== null ? (int) $catalog : null;
    }
}
