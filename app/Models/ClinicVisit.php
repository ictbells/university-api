<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClinicVisit extends BaseModel
{
    protected $fillable = ['student_id', 'staff_id', 'visited_on', 'complaint', 'diagnosis', 'notes'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function bill(): HasOne
    {
        return $this->hasOne(MedicalBill::class);
    }
}
