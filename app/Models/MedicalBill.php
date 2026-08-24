<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalBill extends BaseModel
{
    protected $fillable = [
        'clinic_visit_id',
        'invoice_id',
        'gross_amount',
        'nhis_covered_amount',
        'student_payable_amount',
        'nhis_applied',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'nhis_covered_amount' => 'decimal:2',
            'student_payable_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'nhis_applied' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }
}
