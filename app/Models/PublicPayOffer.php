<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicPayOffer extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'instructions',
        'fee_item_id',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class, 'fee_item_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PublicPayRequest::class, 'offer_id');
    }

    public function isPubliclyAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $fee = $this->relationLoaded('feeItem') ? $this->feeItem : $this->feeItem()->first();

        return $fee
            && $fee->is_active
            && (float) $fee->amount > 0;
    }
}
