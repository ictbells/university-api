<?php

namespace App\Services;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TwoFactorChallengeService
{
    public function create(User $user, bool $setupRequired): string
    {
        $challengeId = (string) Str::uuid();
        Cache::put($this->key($challengeId), [
            'user_id' => $user->id,
            'setup_required' => $setupRequired,
            'pending_secret' => null,
        ], now()->addMinutes(10));

        return $challengeId;
    }

    public function get(string $challengeId): ?array
    {
        return Cache::get($this->key($challengeId));
    }

    public function user(string $challengeId): ?User
    {
        $challenge = $this->get($challengeId);
        if (! $challenge) {
            return null;
        }

        return User::query()->find($challenge['user_id']);
    }

    public function beginSetup(string $challengeId): ?array
    {
        $challenge = $this->get($challengeId);
        $user = $this->user($challengeId);
        if (! $challenge || ! $user) {
            return null;
        }

        $secret = Totp::generateSecret();
        $challenge['pending_secret'] = $secret;
        Cache::put($this->key($challengeId), $challenge, now()->addMinutes(10));

        $issuer = config('app.name', 'Bells Staff Portal');
        $otpauthUrl = Totp::provisioningUri($secret, $user->email, $issuer);

        return [
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'qr_code' => Totp::qrDataUri($otpauthUrl),
        ];
    }

    public function confirm(string $challengeId, string $code): ?User
    {
        $challenge = $this->get($challengeId);
        $user = $this->user($challengeId);
        if (! $challenge || ! $user) {
            return null;
        }

        $secret = $challenge['pending_secret'] ?? $user->two_factor_secret;
        if (! $secret || ! Totp::verify($secret, $code)) {
            return null;
        }

        if ($challenge['setup_required'] || $challenge['pending_secret']) {
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        Cache::forget($this->key($challengeId));

        return $user->fresh();
    }

    public function verifyLogin(string $challengeId, string $code): ?User
    {
        $challenge = $this->get($challengeId);
        $user = $this->user($challengeId);
        if (! $challenge || ! $user || ($challenge['setup_required'] ?? false)) {
            return null;
        }

        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $code)) {
            return null;
        }

        Cache::forget($this->key($challengeId));

        return $user->fresh();
    }

    private function key(string $challengeId): string
    {
        return 'staff_2fa_challenge:'.$challengeId;
    }
}
