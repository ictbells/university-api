<?php

namespace App\Http\Requests;

use App\Support\ApplicantImportColumns;
use Illuminate\Foundation\Http\FormRequest;

class ImportApplicantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'intake_id' => 'required|integer|exists:intakes,id',
            'entry_mode' => 'required|in:'.implode(',', ApplicantImportColumns::MODES),
            'verify_nin' => 'sometimes|boolean',
            'send_credentials' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Spreadsheet file is required.',
            'file.mimes' => 'The file must be Excel (.xlsx, .xls) or CSV.',
            'file.max' => 'The file must not exceed 10MB.',
            'intake_id.required' => 'Select an application window.',
            'entry_mode.required' => 'Select an applicant category.',
        ];
    }
}
