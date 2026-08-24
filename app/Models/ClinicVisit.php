<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClinicVisit extends BaseModel
{
    protected $fillable = [
        'student_id',
        'staff_id',
        'status',
        'visit_type',
        'visited_on',
        'scheduled_at',
        'triage_priority',
        'complaint',
        'diagnosis',
        'notes',
        'temperature',
        'pulse',
        'bp_systolic',
        'bp_diastolic',
        'weight_kg',
        'height_cm',
        'disposition',
        'notes_internal',
    ];

    protected function casts(): array
    {
        return [
            'visited_on' => 'date',
            'scheduled_at' => 'datetime',
            'notes_internal' => 'boolean',
            'temperature' => 'decimal:1',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function bill(): HasOne
    {
        return $this->hasOne(MedicalBill::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClinicVisitItem::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function sickNotes(): HasMany
    {
        return $this->hasMany(SickNote::class);
    }
}
