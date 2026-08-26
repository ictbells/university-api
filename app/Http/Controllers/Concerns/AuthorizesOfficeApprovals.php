<?php

namespace App\Http\Controllers\Concerns;

use App\Services\OfficeApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

trait AuthorizesOfficeApprovals
{
    protected function officeGate(
        string $actionKey,
        ?Model $subject,
        array $payload,
        string $summary,
        callable $execute,
        ?string $navKey = null,
    ): mixed {
        return app(OfficeApprovalService::class)->submitOrExecute(
            $actionKey,
            $subject,
            $payload,
            $summary,
            $navKey,
            $execute,
        );
    }

    /**
     * Store an upload so office-approval replay can restore it onto the request.
     *
     * @return array{_uploaded_file_path?: string, _uploaded_file_name?: string, _uploaded_file_field?: string}
     */
    protected function persistApprovalUpload(Request $request, string $field = 'file'): array
    {
        $file = $request->file($field);
        if (! $file instanceof UploadedFile) {
            return [];
        }

        return [
            '_uploaded_file_path' => $file->store('office-approval-uploads'),
            '_uploaded_file_name' => $file->getClientOriginalName(),
            '_uploaded_file_field' => $field,
        ];
    }
}
