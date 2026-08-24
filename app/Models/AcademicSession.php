<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends BaseModel
{
    protected $fillable = ['label', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function semesters(): HasMany
    {
        return $this->hasMany(AcademicTerm::class, 'academic_session_id')->orderBy('id');
    }

    public function isCurrent(): bool
    {
        return $this->semesters()->where('is_current', true)->exists();
    }
}
