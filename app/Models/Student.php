<?php

namespace App\Models;

use App\Support\NinCipher;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends BaseModel
{
    public const NIN_LOCKED = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'nin', 'photo_path',
    ];

    protected $guarded = [];

    protected $hidden = ['nin_hash'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'graduated_at' => 'date:Y-m-d',
            'studentship_expires_at' => 'date:Y-m-d',
            'nin_locked' => 'boolean',
            'nin' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Student $student) {
            $plain = is_string($student->nin) ? NinCipher::normalize($student->nin) : '';
            $student->nin_hash = $plain !== '' ? NinCipher::hash($plain) : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function unitGraces(): HasMany
    {
        return $this->hasMany(UnitGrace::class);
    }

    public function registrationExtensions(): HasMany
    {
        return $this->hasMany(RegistrationExtension::class);
    }

    public function medicalProfile(): HasOne
    {
        return $this->hasOne(MedicalProfile::class);
    }

    public function pgRecord(): HasOne
    {
        return $this->hasOne(PgRecord::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function programmeChanges(): HasMany
    {
        return $this->hasMany(StudentProgrammeChange::class)->orderBy('id');
    }

    public function hostelAllocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class);
    }

    public function activeHostelAllocation(): HasOne
    {
        return $this->hasOne(HostelAllocation::class)->where('status', 'allocated')->latestOfMany();
    }
}
