<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StudentPortalAuth
{
    public static function normalizeLogin(string $login): string
    {
        return strtoupper(str_replace(' ', '', trim($login)));
    }

    public static function looksLikeMatric(string $login): bool
    {
        return str_contains($login, '/') || str_starts_with($login, 'BUT');
    }

    /**
     * @throws ValidationException
     */
    public static function resolveUser(string $login): ?User
    {
        $key = self::normalizeLogin($login);
        if ($key === '') {
            return null;
        }

        if (self::looksLikeMatric($key)) {
            $student = Student::query()
                ->whereRaw('UPPER(matric_number) = ?', [$key])
                ->with('user')
                ->first();

            return $student?->user;
        }

        $user = User::query()->where('jamb_registration', $key)->with('student')->first();
        if (! $user) {
            return null;
        }

        if ($user->student?->matric_number) {
            throw ValidationException::withMessages([
                'login' => 'You have been matriculated. Please sign in with your matric number instead of your JAMB registration number.',
            ]);
        }

        return $user;
    }
}
