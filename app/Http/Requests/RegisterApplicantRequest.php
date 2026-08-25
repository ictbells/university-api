<?php

namespace App\Http\Requests;

use App\Models\Intake;
use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        if ($this->has('jamb_registration')) {
            $jamb = strtoupper(str_replace(' ', '', (string) $this->input('jamb_registration')));
            $this->merge(['jamb_registration' => $jamb === '' ? null : $jamb]);
        }
    }

    public function rules(): array
    {
        return [
            'nin' => 'required|string|size:11',
            'email' => 'required|string|email:rfc,dns|max:255|unique:users,email',
            'phone' => 'required|string|max:30',
            'password' => PasswordRules::rules(),
            'intake_id' => 'required|integer|exists:intakes,id',
            'jamb_registration' => ['nullable', 'string', 'max:20', Rule::unique('users', 'jamb_registration')],
        ];
    }

    public function messages(): array
    {
        return array_merge(PasswordRules::messages(), [
            'intake_id.required' => 'Select an application session before creating an account.',
            'intake_id.exists' => 'Select an application session before creating an account.',
        ]);
    }

    private function cleanEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $email = preg_replace('/\.(com|org|net|edu|gov)(com|org|net|edu|gov)$/i', '.$1', $email);

        return rtrim($email, '.');
    }
}
