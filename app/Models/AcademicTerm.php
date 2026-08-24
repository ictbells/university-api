<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicTerm extends BaseModel
{
    protected $fillable = [
        'academic_session_id',
        'name',
        'session_label',
        'starts_on',
        'ends_on',
        'normal_registration_closes_at',
        'late_registration_closes_at',
        'extension_price_per_unit',
        'is_current',
        'auto_schedule',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'auto_schedule' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'normal_registration_closes_at' => 'datetime',
            'late_registration_closes_at' => 'datetime',
            'extension_price_per_unit' => 'decimal:2',
        ];
    }

    public function registrationStatus(): string
    {
        $now = now();
        $lateCloses = $this->late_registration_closes_at;
        $normalCloses = $this->normal_registration_closes_at;

        if ($lateCloses) {
            if ($now->greaterThan($lateCloses)) {
                return 'Closed';
            }
            if ($normalCloses && $now->greaterThan($normalCloses)) {
                return 'Late';
            }

            return 'Open';
        }

        if ($normalCloses) {
            return $now->greaterThan($normalCloses) ? 'Closed' : 'Open';
        }

        if ($this->ends_on && $now->copy()->startOfDay()->greaterThan($this->ends_on)) {
            return 'Closed';
        }

        if ($this->starts_on && $now->copy()->endOfDay()->lessThan($this->starts_on)) {
            return 'Closed';
        }

        return $this->is_current ? 'Open' : 'Closed';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public static function current(): ?self
    {
        return static::query()->with('session')->where('is_current', true)->first();
    }

    protected static function booted(): void
    {
        static::saving(function (AcademicTerm $term) {
            if ($term->academic_session_id && (! $term->session_label || $term->isDirty('academic_session_id'))) {
                $label = AcademicSession::query()->whereKey($term->academic_session_id)->value('label');
                if ($label) {
                    $term->session_label = $label;
                }
            }
        });
    }
}
