<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicPayRequest extends BaseModel
{
    public const STATUSES = [
        'awaiting_payment',
        'paid',
        'processing',
        'ready',
        'rejected',
        'cancelled',
    ];

    public const DELIVERY_MODES = [
        'collect',
        'uploaded',
    ];

    protected $fillable = [
        'public_token',
        'student_id',
        'offer_id',
        'invoice_id',
        'contact_email',
        'purpose',
        'status',
        'delivery_mode',
        'artifact_path',
        'rejected_reason',
        'processed_by',
        'paid_at',
        'ready_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(PublicPayOffer::class, 'offer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready'
            && $this->delivery_mode === 'uploaded'
            && filled($this->artifact_path);
    }
}
