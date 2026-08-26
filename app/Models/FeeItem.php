<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeItem extends BaseModel
{
    protected $fillable = [
        'name',
        'description',
        'category',
        'installment_tranche',
        'entry_mode',
        'transcript_type',
        'program_id',
        'amount',
        'wallet_allowed',
        'is_required',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'wallet_allowed' => 'boolean',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'amount' => 'decimal:2',
            'display_order' => 'integer',
            'installment_tranche' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
