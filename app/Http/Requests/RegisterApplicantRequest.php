<?php

namespace App\Http\Requests;

use App\Models\Intake;
use App\Support\BillingCurrency;
use App\Support\PasswordRules;
use App\Support\PhoneNumber;
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
        if ($this->has('passport_number')) {
            $passport = \App\Models\Application::normalizePassport($this->input('passport_number'));
            $this->merge(['passport_number' => $passport === '' ? null : $passport]);
        }
        if ($this->has('jamb_registration')) {
            $jamb = strtoupper(str_replace(' ', '', (string) $this->input('jamb_registration')));
            $this->merge(['jamb_registration' => $jamb === '' ? null : $jamb]);
        }
        if (! $this->filled('alternate_phone')) {
            $this->merge(['alternate_phone' => null]);
        }
    }

    public function rules(): array
    {
        $foreignPostgraduate = $this->isForeignPostgraduate();

        return [
            'nin' => [Rule::requiredIf(! $foreignPostgraduate), 'nullable', 'string', 'size:11'],
            'passport_number' => [Rule::requiredIf($foreignPostgraduate), 'nullable', 'string', 'min:5', 'max:40'],
            'first_name' => [Rule::requiredIf($foreignPostgraduate), 'nullable', 'string', 'max:80'],
            'middle_name' => 'nullable|string|max:80',
            'last_name' => [Rule::requiredIf($foreignPostgraduate), 'nullable', 'string', 'max:80'],
            'date_of_birth' => [Rule::requiredIf($foreignPostgraduate), 'nullable', 'date', 'before:today'],
            'gender' => [Rule::requiredIf($foreignPostgraduate), 'nullable', 'in:Male,Female'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'phone' => 'nullable|string|max:30',
            'alternate_phone' => ['required', 'string', 'max:30', new PhoneNumber],
            'password' => PasswordRules::rules(),
            'intake_id' => 'required|integer|exists:intakes,id',
            'country' => [
                Rule::requiredIf(function () {
                    $intake = Intake::query()->find($this->input('intake_id'));

                    return $intake?->entry_mode === 'pg';
                }),
                'nullable',
                Rule::in([BillingCurrency::COUNTRY_NIGERIA, BillingCurrency::COUNTRY_NON_NIGERIA]),
            ],
            'jamb_registration' => ['nullable', 'string', 'max:20', Rule::unique('users', 'jamb_registration')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return array_merge(PasswordRules::messages(), [
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'An account already exists with this email. Sign in instead.',
            'jamb_registration.unique' => 'This JAMB number is already linked to an account.',
            'intake_id.required' => 'Select an application session before creating an account.',
            'intake_id.exists' => 'Select an application session before creating an account.',
            'alternate_phone.required' => 'Enter an alternate phone number. This is required even if your NIN already has a phone number.',
            'passport_number.required' => 'Enter your passport or national ID number.',
            'passport_number.min' => 'Enter the passport or ID number as it appears on the document.',
        ]);
    }

    private function isForeignPostgraduate(): bool
    {
        $intake = Intake::query()->find($this->input('intake_id'));

        return $intake?->entry_mode === 'pg'
            && $this->input('country') === BillingCurrency::COUNTRY_NON_NIGERIA;
    }

    private function cleanEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $email = preg_replace('/\.(com|org|net|edu|gov)(com|org|net|edu|gov)$/i', '.$1', $email);

        return rtrim($email, '.');
    }
}
