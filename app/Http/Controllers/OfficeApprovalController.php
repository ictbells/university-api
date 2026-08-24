<?php

namespace App\Http\Controllers;

use App\Models\OfficeApprovalRequest;
use App\Services\OfficeApprovalService;
use Illuminate\Http\Request;

class OfficeApprovalController extends Controller
{
    public function __construct(private OfficeApprovalService $approvals) {}

    public function index(Request $request)
    {
        $scope = $request->query('scope');
        abort_unless(in_array($scope, [null, 'review', 'submitted', 'decided'], true), 422);

        return $this->approvals->inbox($request->user(), $scope);
    }

    public function show(OfficeApprovalRequest $officeApproval)
    {
        $user = request()->user();
        abort_unless(
            $this->approvals->isReviewer($user) || (int) $officeApproval->requester_user_id === (int) $user->id,
            403,
        );

        return $this->approvals->serialize($officeApproval, $user);
    }

    public function approve(Request $request, OfficeApprovalRequest $officeApproval)
    {
        $data = $request->validate(['comment' => 'nullable|string|max:1000']);

        return $this->approvals->decide($officeApproval, $request->user(), 'approve', $data['comment'] ?? null);
    }

    public function reject(Request $request, OfficeApprovalRequest $officeApproval)
    {
        $data = $request->validate(['comment' => 'nullable|string|max:1000']);

        return $this->approvals->decide($officeApproval, $request->user(), 'reject', $data['comment'] ?? null);
    }
}
