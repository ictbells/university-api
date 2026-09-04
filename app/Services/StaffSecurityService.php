<?php

namespace App\Services;

use App\Models\User;
use App\Support\SecuritySettings;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class StaffSecurityService
{
    public const SESSION_MAX_HOURS = 8;

    public function appliesTo(User $user): bool
    {
        return $user->isStaffPortalUser();
    }

    public function twoFactorRequired(User $user): bool
    {
        return $this->appliesTo($user) && SecuritySettings::twoFactorEnabled();
    }

    public function hasTwoFactorConfigured(User $user): bool
    {
        return (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at);
    }

    public function passwordChangeRequired(User $user): bool
    {
        if (! $this->appliesTo($user)) {
            return false;
        }

        $days = SecuritySettings::passwordRotationDays();
        if ($days <= 0) {
            return false;
        }

        $changedAt = $user->password_changed_at ?? $user->created_at;
        if (! $changedAt) {
            return true;
        }

        return Carbon::parse($changedAt)->addDays($days)->isPast();
    }

    public function passwordExpiresAt(User $user): ?string
    {
        $days = SecuritySettings::passwordRotationDays();
        if ($days <= 0 || ! $this->appliesTo($user)) {
            return null;
        }

        $changedAt = $user->password_changed_at ?? $user->created_at;
        if (! $changedAt) {
            return null;
        }

        return Carbon::parse($changedAt)->addDays($days)->toIso8601String();
    }

    public function inactivityExceeded(User $user): bool
    {
        if (! $this->appliesTo($user)) {
            return false;
        }

        $minutes = SecuritySettings::inactivityLogoutMinutes();
        if ($minutes <= 0 || ! $user->last_activity_at) {
            return false;
        }

        return Carbon::parse($user->last_activity_at)->addMinutes($minutes)->isPast();
    }

    public function sessionExceeded(User $user): bool
    {
        $expiresAt = $this->sessionExpiresAtCarbon($user);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public function sessionExpiresAt(User $user): ?string
    {
        return $this->sessionExpiresAtCarbon($user)?->toIso8601String();
    }

    public function revokeSpaAccess(User $user): void
    {
        $token = $this->resolveSpaToken($user);
        $token?->delete();
    }

    private function sessionExpiresAtCarbon(User $user): ?Carbon
    {
        if (! $this->appliesTo($user)) {
            return null;
        }

        $createdAt = $this->resolveSpaToken($user)?->created_at;
        if (! $createdAt) {
            return null;
        }

        return Carbon::parse($createdAt)->addHours(self::SESSION_MAX_HOURS);
    }

    private function resolveSpaToken(User $user): ?PersonalAccessToken
    {
        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            return $current;
        }

        $bearer = request()?->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $fromBearer = PersonalAccessToken::findToken($bearer);
            if ($fromBearer
                && (int) $fromBearer->tokenable_id === (int) $user->id
                && $fromBearer->tokenable_type === $user->getMorphClass()
            ) {
                return $fromBearer;
            }
        }

        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where('name', 'spa')
            ->latest('id')
            ->first();
    }

    public function touchActivity(User $user): void
    {
        if (! $this->appliesTo($user)) {
            return;
        }

        $user->forceFill(['last_activity_at' => now()])->save();
    }

    public function markPasswordChanged(User $user): void
    {
        $user->forceFill(['password_changed_at' => now()])->save();
    }

    public function policyPayload(User $user): array
    {
        return [
            'two_factor_policy_enabled' => SecuritySettings::twoFactorEnabled(),
            'two_factor_configured' => $this->hasTwoFactorConfigured($user),
            'password_rotation_days' => SecuritySettings::passwordRotationDays(),
            'password_change_required' => $this->passwordChangeRequired($user),
            'password_expires_at' => $this->passwordExpiresAt($user),
            'inactivity_logout_minutes' => SecuritySettings::inactivityLogoutMinutes(),
            'session_max_hours' => self::SESSION_MAX_HOURS,
            'session_expires_at' => $this->sessionExpiresAt($user),
        ];
    }
}
