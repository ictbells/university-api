<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends BaseModel
{
    protected $table = 'staff';

    protected $fillable = [
        'user_id',
        'department_id',
        'office_department_id',
        'office_unit_id',
        'office_subunit_id',
        'staff_number',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
