<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfficeApprovalRequest extends BaseModel
{
    use SoftDeletes;

    public const PENDING_UNIT_HEAD = 'pending_unit_head';

    public const PENDING_HOD = 'pending_hod';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'office_department_id',
        'office_unit_id',
        'requester_user_id',
        'action_key',
        'nav_key',
        'subject_type',
        'subject_id',
        'payload',
        'summary',
        'status',
        'unit_reviewed_by',
        'unit_reviewed_at',
        'unit_comment',
        'hod_reviewed_by',
        'hod_reviewed_at',
        'hod_comment',
        'executed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'unit_reviewed_at' => 'datetime',
            'hod_reviewed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(OfficeDepartment::class, 'office_department_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OfficeUnit::class, 'office_unit_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function unitReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unit_reviewed_by');
    }

    public function hodReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hod_reviewed_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING_UNIT_HEAD, self::PENDING_HOD], true);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::PENDING_UNIT_HEAD, self::PENDING_HOD]);
    }
}
