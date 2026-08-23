<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends BaseModel
{
    public const FORM_STEPS = [
        'biodata',
        'application_form',
        'academic_qualifications',
        'programme_selection',
        'required_documents',
    ];

    public const STAFF_STAGES = [
        'submitted' => 'screening',
        'screening' => 'verification',
        'verification' => 'shortlisting',
        'shortlisting' => 'recommended',
        'recommended' => 'approved',
        'approved' => 'offer_issued',
    ];

    public const STAGE_PERMISSION = [
        'screening' => 'admissions.screen',
        'verification' => 'admissions.verify',
        'shortlisting' => 'admissions.shortlist',
        'recommended' => 'admissions.recommend',
        'approved' => 'admissions.approve',
        'offer_issued' => 'admissions.offer',
        'matriculated' => 'admissions.matriculate',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function applicationFeeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'application_fee_invoice_id');
    }

    public function acceptanceFeeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'acceptance_fee_invoice_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApplicationStep::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ApplicationReview::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function ninVerified(): bool
    {
        $payload = $this->steps()->where('step_key', 'biodata')->value('payload');

        return is_array($payload) && ($payload['nin_locked'] ?? false);
    }
}
