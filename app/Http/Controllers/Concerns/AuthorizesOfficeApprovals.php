<?php

namespace App\Http\Controllers\Concerns;

use App\Services\OfficeApprovalService;
use Illuminate\Database\Eloquent\Model;

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
}
