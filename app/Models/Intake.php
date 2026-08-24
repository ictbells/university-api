<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class Intake extends BaseModel
{
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
            throw new RuntimeException('Set the application fee on this application window before applicants can apply.');
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
}
