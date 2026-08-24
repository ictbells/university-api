<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceRebate extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rebateType(): BelongsTo
    {
        return $this->belongsTo(RebateType::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isActive(): bool
    {
        return $this->reversed_at === null;
    }
}
