<?php

namespace App\Models;

class MedicalProfile extends BaseModel
{
    protected $fillable = [
        'student_id',
        'blood_type',
        'genotype',
        'has_medical_condition',
        'allergies',
        'conditions',
        'nhis_enrolled',
        'nhis_number',
        'nhis_provider',
        'nhis_coverage_percent',
        'nhis_coverage_amount',
        'nhis_valid_until',
    ];

    protected function casts(): array
    {
        return [
            'has_medical_condition' => 'boolean',
            'nhis_enrolled' => 'boolean',
            'nhis_coverage_percent' => 'decimal:2',
            'nhis_coverage_amount' => 'decimal:2',
            'nhis_valid_until' => 'date',
        ];
    }
}
