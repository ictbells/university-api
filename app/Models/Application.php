<?php

namespace App\Models;

use App\Support\ApplicationFormSteps;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends BaseModel
{
    public const SHARED_FORM_STEPS = [
        'biodata',
        'personal_details',
        'health_information',
        'next_of_kin',
        'sponsor',
        'application_form',
        'utme',
        'academic_qualifications',
        'programme_selection',
        'required_documents',
    ];

    public const DE_FORM_STEPS = [
        'direct_entry',
    ];

    public const TRANSFER_FORM_STEPS = [
        'transfer_background',
    ];

    public const PG_FORM_STEPS = [
        'pg_background',
        'pg_research',
        'pg_referees',
    ];

    public const FORM_STEPS = [
        ...self::SHARED_FORM_STEPS,
        ...self::DE_FORM_STEPS,
        ...self::TRANSFER_FORM_STEPS,
        ...self::PG_FORM_STEPS,
    ];

    /**
     * @return list<string>
     */
    public static function formSteps(?string $entryMode = null): array
    {
        $steps = [
            'biodata',
            'personal_details',
            'health_information',
            'next_of_kin',
            'sponsor',
            'application_form',
        ];
        if ($entryMode === 'utme') {
            $steps[] = 'utme';
        }
        $steps[] = 'academic_qualifications';
        if ($entryMode === 'de') {
            $steps[] = 'direct_entry';
        }
        if ($entryMode === 'transfer') {
            $steps[] = 'transfer_background';
        }
        if ($entryMode === 'pg') {
            $steps[] = 'pg_background';
        }
        $steps[] = 'programme_selection';
        if ($entryMode === 'pg') {
            $steps[] = 'pg_research';
            $steps[] = 'pg_referees';
        }
        $steps[] = 'required_documents';

        return $steps;
    }

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

    public const TRANSFER_STAFF_STAGES = [
        'submitted' => 'screening',
        'screening' => 'verification',
        'verification' => 'credit_assessment',
        'credit_assessment' => 'shortlisting',
        'shortlisting' => 'recommended',
        'recommended' => 'approved',
        'approved' => 'offer_issued',
    ];

    public const STAGE_PERMISSION = [
        'screening' => 'admissions.screen',
        'verification' => 'admissions.verify',
        'credit_assessment' => 'admissions.credit_assess',
        'shortlisting' => 'admissions.shortlist',
        'recommended' => 'admissions.recommend',
        'approved' => 'admissions.approve',
        'offer_issued' => 'admissions.offer',
        'matriculated' => 'admissions.matriculate',
    ];

    /**
     * @return array<string, string>
     */
    public static function staffStagesFor(?string $entryMode): array
    {
        return $entryMode === 'transfer' ? self::TRANSFER_STAFF_STAGES : self::STAFF_STAGES;
    }

    public function ensureFormSteps(): void
    {
        $this->loadMissing('steps');
        $existing = $this->steps->pluck('step_key')->all();
        $editable = in_array($this->stage, ['awaiting_application_fee', 'fee_paid', 'form_in_progress'], true);
        foreach (self::formSteps($this->entry_mode) as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            $this->steps()->create([
                'step_key' => $key,
                'status' => $editable ? 'pending' : 'saved',
                'payload' => [],
            ]);
        }
        $this->unsetRelation('steps');
        $this->load('steps');
        $this->migrateUtmeFromAcademicQualifications();
    }

    /**
     * Move legacy UTME blob out of academic_qualifications into the dedicated utme step.
     */
    public function migrateUtmeFromAcademicQualifications(): void
    {
        if ($this->entry_mode !== 'utme') {
            return;
        }

        $this->loadMissing('steps');
        $academic = $this->steps->firstWhere('step_key', 'academic_qualifications');
        $utmeStep = $this->steps->firstWhere('step_key', 'utme');
        if (! $academic || ! $utmeStep) {
            return;
        }

        $academicPayload = is_array($academic->payload) ? $academic->payload : [];
        $legacyUtme = is_array($academicPayload['utme'] ?? null) ? $academicPayload['utme'] : null;
        if (! $legacyUtme || ApplicationFormSteps::utmeIsEmpty($legacyUtme)) {
            return;
        }

        $utmePayload = is_array($utmeStep->payload) ? $utmeStep->payload : [];
        $existingUtme = is_array($utmePayload['utme'] ?? null) ? $utmePayload['utme'] : null;
        if (ApplicationFormSteps::utmeIsEmpty($existingUtme)) {
            $utmeStep->update([
                'payload' => ['utme' => $legacyUtme],
                'status' => $utmeStep->status === 'pending' ? 'saved' : $utmeStep->status,
            ]);
        }

        unset($academicPayload['utme']);
        $academic->update(['payload' => $academicPayload]);
        $this->unsetRelation('steps');
        $this->load('steps');
    }

    public function transferAssessmentComplete(): bool
    {
        if ($this->entry_mode !== 'transfer') {
            return true;
        }
        $this->loadMissing('steps');
        $payload = $this->steps->firstWhere('step_key', 'credit_assessment')?->payload;

        return ApplicationFormSteps::assessmentAcceptsTransfer(is_array($payload) ? $payload : null);
    }

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

    public function latestReview(): HasOne
    {
        return $this->hasOne(ApplicationReview::class)->latestOfMany();
    }

    public function refereeInvites(): HasMany
    {
        return $this->hasMany(RefereeInvite::class)->orderBy('position');
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
