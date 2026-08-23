<?php

namespace App\Http\Requests;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class RegisterApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => $this->cleanEmail((string) $this->input('email'))]);
        }
        if ($this->has('jamb_registration')) {
            $this->merge(['jamb_registration' => strtoupper(str_replace(' ', '', (string) $this->input('jamb_registration')))]);
        }
        if ($this->has('nin')) {
            $this->merge(['nin' => preg_replace('/\D/', '', (string) $this->input('nin'))]);
        }
    }

    public function rules(): array
    {
        return [
            'nin' => 'required|string|size:11',
            'email' => 'required|string|email:rfc,dns|max:255|unique:users,email',
            'jamb_registration' => 'required|string|max:20|unique:users,jamb_registration',
            'phone' => 'required|string|max:30',
            'password' => PasswordRules::rules(),
        ];
    }

    public function messages(): array
    {
        return array_merge(PasswordRules::messages(), [
            'jamb_registration.unique' => 'This JAMB registration number is already registered.',
        ]);
    }

    private function cleanEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $email = preg_replace('/\.(com|org|net|edu|gov)(com|org|net|edu|gov)$/i', '.$1', $email);

        return rtrim($email, '.');
    }
}
