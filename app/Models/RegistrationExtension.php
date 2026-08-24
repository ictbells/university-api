<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationExtension extends BaseModel
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'paid', 'expired', 'cancelled'];

    public const ACTIVE = ['pending', 'approved', 'paid'];

    protected $fillable = [
        'student_id',
        'academic_term_id',
        'requested_units',
        'approved_units',
        'status',
        'reason',
        'staff_note',
        'invoice_id',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'paid_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPaidActive(): bool
    {
        if ($this->status !== 'paid') {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->greaterThanOrEqualTo(now());
    }
}
