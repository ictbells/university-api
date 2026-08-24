<?php

namespace App\Models;

use App\Support\GradeStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends BaseModel
{
    protected $fillable = [
        'enrollment_id',
        'sitting',
        'letter',
        'points',
        'score',
        'ca_score',
        'exam_score',
        'status',
        'source',
        'source_ref',
        'upload_lane',
        'faculty_id',
        'department_id',
        'entered_by',
        'submitted_at',
        'faculty_approved_at',
        'board_cleared_at',
        'released_at',
        'correction_note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'score' => 'decimal:2',
            'ca_score' => 'decimal:2',
            'exam_score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'faculty_approved_at' => 'datetime',
            'board_cleared_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected $appends = ['registration_held'];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(GradeStatusEvent::class);
    }

    public function isEditable(): bool
    {
        return GradeStatus::isEditable((string) $this->status);
    }

    public function isReleased(): bool
    {
        return GradeStatus::isReleased((string) $this->status);
    }

    public function getRegistrationHeldAttribute(): bool
    {
        $enrollment = $this->relationLoaded('enrollment')
            ? $this->enrollment
            : $this->enrollment()->first();

        return ! $enrollment || $enrollment->status !== 'enrolled';
    }
}
