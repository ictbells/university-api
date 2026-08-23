<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostelAllocation extends BaseModel
{
    protected $fillable = ['student_id', 'hostel_bed_id', 'academic_term_id', 'status', 'allocated_at', 'vacated_at'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(HostelBed::class, 'hostel_bed_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
