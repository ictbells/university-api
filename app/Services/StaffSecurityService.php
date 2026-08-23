<?php

namespace App\Services;

use App\Models\User;
use App\Support\SecuritySettings;
use Carbon\Carbon;

class StaffSecurityService
{
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
        ];
    }
}
