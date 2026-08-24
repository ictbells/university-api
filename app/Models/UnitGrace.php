<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitGrace extends BaseModel
{
    protected $fillable = [
        'student_id',
        'academic_term_id',
        'bucket',
        'extra_units',
        'reason',
        'granted_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
