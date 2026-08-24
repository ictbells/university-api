<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends BaseModel
{
    public const FORM_STEPS = [
        'biodata',
        'personal_details',
        'health_information',
        'next_of_kin',
        'sponsor',
        'application_form',
        'academic_qualifications',
        'programme_selection',
        'required_documents',
    ];

    /** Steps that hold applicant profile fields used for printouts and student creation. */
    public const PROFILE_STEPS = [
        'biodata',
        'personal_details',
        'health_information',
        'next_of_kin',
        'sponsor',
        'application_form',
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

    /**
     * Merge profile-related step payloads (later steps override earlier keys).
     *
     * @return array<string, mixed>
     */
    public function mergedProfilePayload(): array
    {
        $this->loadMissing('steps');
        $merged = [];
        foreach (self::PROFILE_STEPS as $key) {
            $payload = $this->steps->firstWhere('step_key', $key)?->payload;
            if (is_array($payload)) {
                $merged = array_merge($merged, $payload);
            }
        }

        return $merged;
    }
}
