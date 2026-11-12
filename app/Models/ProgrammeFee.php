<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammeFee extends BaseModel
{
    protected $fillable = [
        'program_id',
        'fee_item_id',
        'amount',
        'amount_usd',
        'installment_tranche',
        'level_code',
        'entry_mode',
        'semester',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_usd' => 'decimal:2',
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

    public function pricedAmount(string $currency): float
    {
        if (! \App\Support\BillingCurrency::isUsd($currency)) {
            return round($this->effective_amount, 2);
        }

        if ($this->amount_usd !== null && (float) $this->amount_usd > 0) {
            return round((float) $this->amount_usd, 2);
        }

        $catalog = $this->feeItem?->amount_usd;
        if ($catalog !== null && (float) $catalog > 0) {
            return round((float) $catalog, 2);
        }

        $name = $this->feeItem?->name ?: 'A school fee';
        throw new \RuntimeException($name.' has no USD amount. Set it on the fee catalog or programme fee before billing a foreign student.');
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
