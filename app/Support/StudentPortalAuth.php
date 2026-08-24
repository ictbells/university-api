<?php

namespace App\Support;

use App\Models\Application;
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

    public static function looksLikeApplicationNumber(string $login): bool
    {
        return str_starts_with($login, 'APP/');
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

        if (filter_var(trim($login), FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'login' => 'Sign in with your application number, JAMB number, or matric number. Email is only used for password reset and notifications.',
            ]);
        }

        if (self::looksLikeApplicationNumber($key)) {
            $application = Application::query()
                ->where('application_number', $key)
                ->with('user.student')
                ->first();

            return $application?->user;
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
