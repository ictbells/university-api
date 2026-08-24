<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Spreadsheet file is required.',
            'file.mimes' => 'The file must be Excel (.xlsx, .xls) or CSV.',
            'file.max' => 'The file must not exceed 10MB.',
        ];
    }
}
