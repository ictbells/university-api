<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptRequest extends BaseModel
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
        'generated_pdf',
        'uploaded_pdf',
    ];

    protected $fillable = [
        'public_token',
        'student_id',
        'program_id',
        'invoice_id',
        'contact_email',
        'copies',
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
            'copies' => 'integer',
            'paid_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
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
            && in_array($this->delivery_mode, ['generated_pdf', 'uploaded_pdf'], true)
            && filled($this->artifact_path);
    }
}
