<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Intake extends BaseModel
{
    public const CLOSED_SIGNUP_MESSAGE = 'Applications are not open. There is no active application session, so you cannot create an account.';

    public const CLOSED_SIGNUP_CODE = 'applications_closed';

    public const INTAKE_NOT_ACCEPTING_CODE = 'intake_not_accepting';

    public const INTAKE_NOT_ACCEPTING_MESSAGE = 'This application session is not accepting applications.';

    public const CLOSED_SUBMIT_MESSAGE = 'The application window for this category has closed. You cannot submit your application.';

    protected $fillable = ['academic_term_id', 'name', 'entry_mode', 'opens_on', 'closes_on', 'is_open', 'application_fee_amount', 'acceptance_fee_amount'];

    protected function casts(): array
    {
        return [
            'opens_on' => 'date',
            'closes_on' => 'date',
            'is_open' => 'boolean',
            'application_fee_amount' => 'decimal:2',
            'acceptance_fee_amount' => 'decimal:2',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function academicSessionId(): ?int
    {
        $this->loadMissing('term');
        $sessionId = $this->term?->academic_session_id;

        return $sessionId ? (int) $sessionId : null;
    }

    public static function assertUniqueEntryMode(string $entryMode, ?int $ignoreId = null): void
    {
        $exists = static::query()
            ->where('entry_mode', $entryMode)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'entry_mode' => 'This admission category already exists. Edit it for the new session instead of creating another.',
        ]);
    }

    public function assertCanRetargetTerm(?int $newTermId): void
    {
        if ($newTermId === null || (int) $this->academic_term_id === (int) $newTermId) {
            return;
        }
        if (! $this->isAcceptingApplications()) {
            return;
        }

        throw ValidationException::withMessages([
            'academic_term_id' => 'Stop accepting applications before assigning this category to a new session.',
        ]);
    }

    public function assertCanDelete(): void
    {
        if (! $this->applications()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'intake' => 'This admission category has applications and cannot be deleted.',
        ]);
    }

    public function isAcceptingApplications(): bool
    {
        if (! $this->is_open) {
            return false;
        }
        $today = now()->startOfDay();
        if ($this->opens_on && $today->lt($this->opens_on)) {
            return false;
        }
        if ($this->closes_on && $today->gt($this->closes_on)) {
            return false;
        }

        return true;
    }

    public function applicationFeeAmount(): float
    {
        if ($this->application_fee_amount === null) {
            throw new RuntimeException('Set the application fee on this application session before applicants can apply.');
        }

        return (float) $this->application_fee_amount;
    }

    public function acceptanceFeeAmount(): ?float
    {
        if ($this->acceptance_fee_amount !== null) {
            return (float) $this->acceptance_fee_amount;
        }

        return null;
    }

    public function scopeAccepting($query)
    {
        return $query->where('is_open', true)
            ->where(function ($q) {
                $q->whereNull('opens_on')->orWhereDate('opens_on', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('closes_on')->orWhereDate('closes_on', '>=', now());
            });
    }

    public static function hasAccepting(): bool
    {
        return static::query()->accepting()->exists();
    }

    public static function abortUnlessAccepting(): void
    {
        if (static::hasAccepting()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => self::CLOSED_SIGNUP_MESSAGE,
            'code' => self::CLOSED_SIGNUP_CODE,
        ], 422));
    }

    public static function requireAccepting(?int $id): self
    {
        if (! static::hasAccepting()) {
            static::abortUnlessAccepting();
        }

        if (! $id) {
            throw ValidationException::withMessages([
                'intake_id' => 'Select an application session before creating an account.',
            ]);
        }

        $intake = static::query()->with('term')->find($id);
        if (! $intake || ! $intake->isAcceptingApplications()) {
            throw new HttpResponseException(response()->json([
                'message' => self::INTAKE_NOT_ACCEPTING_MESSAGE,
                'code' => self::INTAKE_NOT_ACCEPTING_CODE,
            ], 422));
        }

        return $intake;
    }
}
