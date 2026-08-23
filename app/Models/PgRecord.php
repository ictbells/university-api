<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PgRecord extends BaseModel
{
    protected $fillable = ['student_id', 'supervisor_staff_id', 'topic', 'proposal_status', 'thesis_status'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'supervisor_staff_id');
    }
}
