<?php

namespace App\Http\Requests;

use App\Models\Intake;
use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class RegisterApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        Intake::abortUnlessAccepting();

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => $this->cleanEmail((string) $this->input('email'))]);
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
            'phone' => 'required|string|max:30',
            'password' => PasswordRules::rules(),
        ];
    }

    public function messages(): array
    {
        return PasswordRules::messages();
    }

    private function cleanEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $email = preg_replace('/\.(com|org|net|edu|gov)(com|org|net|edu|gov)$/i', '.$1', $email);

        return rtrim($email, '.');
    }
}
