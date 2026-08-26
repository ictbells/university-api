<?php

namespace App\Models;

use App\Support\ProgrammeFeeResolver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'jamb_registration',
        'password',
        'status',
        'portal_credential_cipher',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function latestApplication(): HasOne
    {
        return $this->hasOne(Application::class)->latestOfMany();
    }

    public function latestNinVerification(): HasOne
    {
        return $this->hasOne(NinVerification::class)->latestOfMany();
    }

    public function permissions(): array
    {
        return $this->roles()
            ->where('is_active', true)
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $key): bool
    {
        if ($this->roles()->where('slug', 'super-admin')->where('is_active', true)->exists()) {
            return true;
        }

        return in_array($key, $this->permissions(), true);
    }

    public function isStudent(): bool
    {
        return (bool) $this->student;
    }

    public function isStaffPortalUser(): bool
    {
        if ($this->staff()->exists()) {
            return true;
        }

        return $this->roles()
            ->where('is_active', true)
            ->whereNotIn('slug', ['applicant', 'student'])
            ->exists();
    }

    public function scopeStaffPortal(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->whereHas('staff')
                ->orWhereHas('roles', fn (Builder $roles) => $roles
                    ->where('is_active', true)
                    ->whereNotIn('slug', ['applicant', 'student']));
        });
    }

    public function isStudentPortalUser(): bool
    {
        if ($this->student) {
            return \App\Support\Studentship::isCurrent($this->student);
        }

        return $this->roles()
            ->where('is_active', true)
            ->where('slug', 'applicant')
            ->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            $user->staff?->delete();
        });
    }

    public function portalAccess(): array
    {
        $application = $this->latestApplication;
        $unpaidAppFee = false;
        $unpaidAcceptance = false;
        $stage = $application?->stage;
        $portal = true;

        if ($application) {
            $fee = $application->applicationFeeInvoice;
            $unpaidAppFee = $fee && in_array($fee->status, ['unpaid', 'partial'], true);
            $acceptance = $application->acceptanceFeeInvoice;
            $offerPending = in_array($application->stage, ['offer_issued', 'awaiting_acceptance_fee', 'admission'], true);
            $acceptanceLive = $acceptance && in_array($acceptance->status, ['unpaid', 'partial', 'paid'], true);
            $unpaidAcceptance = ! $this->isStudent()
                && $offerPending
                && (! $acceptanceLive || in_array($acceptance->status, ['unpaid', 'partial'], true));
            if ($unpaidAppFee && in_array($application->stage, ['started', 'awaiting_application_fee'], true)) {
                $portal = false;
            }
        }

        $programmeFeeTotal = 0.0;
        $programmeFeeSet = false;
        $student = $this->relationLoaded('student') ? $this->student : $this->student()->with('program')->first();
        if ($student) {
            $programmeFeeTotal = ProgrammeFeeResolver::totalForStudent($student);
            $programmeFeeSet = $programmeFeeTotal > 0;
        }

        return [
            'lifecycle_stage' => $stage,
            'is_student' => $this->isStudent(),
            'is_staff' => $this->isStaffPortalUser(),
            'portal_access' => $portal || $this->isStudent() || $this->staff()->exists() || $this->hasPermission('users.manage'),
            'unpaid_application_fee' => $unpaidAppFee,
            'unpaid_acceptance_fee' => $unpaidAcceptance,
            'application_id' => $application?->id,
            'programme_fee_set' => $programmeFeeSet,
            'programme_fee_total' => $programmeFeeSet ? $programmeFeeTotal : null,
        ];
    }
}
