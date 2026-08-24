<?php

namespace App\Models;

use App\Models\BaseModel;

use App\Models\Concerns\HasOfficeNavLinks;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeUnit extends BaseModel
{
    use HasOfficeNavLinks;
    protected $fillable = ['office_department_id', 'name', 'code', 'description', 'is_active', 'head_staff_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(OfficeDepartment::class, 'office_department_id');
    }

    public function subunits(): HasMany
    {
        return $this->hasMany(OfficeSubunit::class)->orderBy('name');
    }

    public function headStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'head_staff_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (OfficeUnit $unit) {
            $unit->subunits()->each(function (OfficeSubunit $subunit) {
                $subunit->delete();
            });
        });
    }
}
