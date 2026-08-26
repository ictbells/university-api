<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCandidateDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'intake_id' => 'required_without:academic_year|nullable|integer|exists:intakes,id',
            'academic_year' => 'required_without:intake_id|nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Spreadsheet file is required.',
            'file.mimes' => 'The file must be Excel (.xlsx, .xls) or CSV.',
            'file.max' => 'The file must not exceed 10MB.',
            'academic_year.required' => 'Application session is required.',
            'academic_year.required_without' => 'Select an application session.',
            'intake_id.required_without' => 'Select an application session.',
            'intake_id.exists' => 'Select a valid application session.',
        ];
    }
}
