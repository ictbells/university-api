<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalBill extends BaseModel
{
    protected $fillable = ['clinic_visit_id', 'invoice_id', 'amount', 'status'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
