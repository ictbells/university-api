<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicVisitItem extends BaseModel
{
    protected $fillable = [
        'clinic_visit_id',
        'fee_item_id',
        'description',
        'quantity',
        'unit_amount',
        'line_total',
        'nhis_covered',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'nhis_covered' => 'boolean',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }
}
