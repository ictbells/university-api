<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intake extends BaseModel
{
    protected $fillable = ['academic_term_id', 'name', 'entry_mode', 'opens_on', 'closes_on', 'is_open'];

    protected function casts(): array
    {
        return [
            'opens_on' => 'date',
            'closes_on' => 'date',
            'is_open' => 'boolean',
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
