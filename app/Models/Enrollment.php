<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Enrollment extends BaseModel
{
    protected $fillable = [
        'student_id',
        'course_offering_id',
        'status',
        'registered_at',
        'dropped_at',
        'registered_by',
        'drop_reason',
        'is_carry_over',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'dropped_at' => 'datetime',
            'is_carry_over' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function grade(): HasOne
    {
        return $this->hasOne(Grade::class)->where('sitting', 'main')->latestOfMany();
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function scopeEnrolled($query)
    {
        return $query->where('status', 'enrolled');
    }
}
