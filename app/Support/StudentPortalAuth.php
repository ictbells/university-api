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
        return strtoupper(preg_replace('/\s+/u', '', trim($login)) ?? '');
    }

    public static function looksLikeMatric(string $login): bool
    {
        return str_contains($login, '/') || str_starts_with($login, 'BUT');
    }

    /**
     * Generated refs are APP/{year}/{serial}. Imports may keep a legacy number
     * such as BELLS-APP-NUM-JUPEB-0119 — those are resolved by DB lookup instead.
     */
    public static function looksLikeApplicationNumber(string $login): bool
    {
        return str_starts_with($login, 'APP/');
    }

    /**
     * @throws ValidationException
     */
    public static function resolveUser(string $login): ?User
    {
        $key = strtr(self::normalizeLogin($login), [
            '／' => '/',
            '∕' => '/',
            '\\' => '/',
        ]);
        if ($key === '') {
            return null;
        }

        if (filter_var(trim($login), FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'login' => 'Sign in with your application number, JAMB number, or matric number. Email is only used for password reset and notifications.',
            ]);
        }

        if (self::looksLikeMatric($key) && ! self::looksLikeApplicationNumber($key)) {
            $student = Student::query()
                ->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$key])
                ->with('user')
                ->first();

            return $student?->user;
        }

        $application = Application::query()
            ->whereRaw('UPPER(REPLACE(COALESCE(application_number, ""), " ", "")) = ?', [$key])
            ->with('user.student')
            ->first();
        if ($application?->user) {
            return $application->user;
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
