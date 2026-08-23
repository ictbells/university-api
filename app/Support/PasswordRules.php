<?php

namespace App\Support;

class PasswordRules
{
    public static function rules(bool $required = true, ?string $emailField = 'email'): array
    {
        $base = [
            $required ? 'required' : 'nullable',
            'string',
            'min:8',
            'confirmed',
            'regex:/[A-Z]/',
            'regex:/[a-z]/',
            'regex:/[0-9]/',
            'regex:/[^A-Za-z0-9]/',
        ];

        if ($emailField) {
            $base[] = 'different:'.$emailField;
        }

        return $base;
    }

    public static function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must include uppercase, lowercase, a number, and a symbol.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.different' => 'Password cannot be the same as the email address.',
        ];
    }
}
