<?php

namespace App\Models;

use App\Models\BaseModel;

use App\Models\Concerns\HasOfficeNavLinks;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeDepartment extends BaseModel
{
    use HasOfficeNavLinks;
    protected $fillable = ['name', 'code', 'description', 'is_active', 'head_staff_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function units(): HasMany
    {
        return $this->hasMany(OfficeUnit::class)->orderBy('name');
    }

    public function headStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'head_staff_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (OfficeDepartment $department) {
            $department->units()->each(function (OfficeUnit $unit) {
                $unit->delete();
            });
        });
    }
}
