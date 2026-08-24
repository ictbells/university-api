<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends BaseModel
{
    protected $fillable = [
        'clinic_visit_id',
        'student_id',
        'staff_id',
        'medication',
        'dosage',
        'frequency',
        'duration',
        'instructions',
        'dispensed_at',
    ];

    protected function casts(): array
    {
        return [
            'dispensed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
