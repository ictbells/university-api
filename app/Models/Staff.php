<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function officeDepartment(): BelongsTo
    {
        return $this->belongsTo(OfficeDepartment::class, 'office_department_id');
    }

    public function officeUnit(): BelongsTo
    {
        return $this->belongsTo(OfficeUnit::class, 'office_unit_id');
    }

    public function officeSubunit(): BelongsTo
    {
        return $this->belongsTo(OfficeSubunit::class, 'office_subunit_id');
    }

    public function headedOfficeDepartment(): HasOne
    {
        return $this->hasOne(OfficeDepartment::class, 'head_staff_id');
    }

    public function headedOfficeUnit(): HasOne
    {
        return $this->hasOne(OfficeUnit::class, 'head_staff_id');
    }
}
