<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SickNote extends BaseModel
{
    protected $fillable = [
        'clinic_visit_id',
        'student_id',
        'staff_id',
        'issued_on',
        'valid_from',
        'valid_to',
        'reason',
        'restrictions',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
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

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
