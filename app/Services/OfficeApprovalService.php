<?php

namespace App\Services;

use App\Models\OfficeApprovalRequest;
use App\Models\OfficeDepartment;
use App\Models\OfficeNavLink;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\User;
use App\Support\OfficeApprovalCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OfficeApprovalService
{
    public static bool $replaying = false;

    public function __construct(
        private OfficeNavOwnerResolver $owners,
        private OfficeApprovalExecutor $executor,
        private Notifier $notifier,
    ) {}

    public function submitOrExecute(
        string $actionKey,
        ?Model $subject,
        array $payload,
        string $summary,
        ?string $navKey = null,
        ?callable $execute = null,
    ): mixed {
        $navKey ??= OfficeApprovalCatalog::navKey($actionKey);
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $run = $execute ?? fn () => $this->executor->run($actionKey, $payload);
        if (self::$replaying) {
            return $run();
        }
        $owner = $this->owners->ownerForNavKey($navKey);

        if (! $owner) {
            return $run();
        }

        if (! $this->mutationRequiresApproval($owner, OfficeApprovalCatalog::mutationFor($actionKey))) {
            return $run();
        }

        $department = $owner['department'];
        $placement = $this->actorPlacement($user);
        $chain = $this->chainFor($user, $department, $placement, $owner['approval_chain']);

        if ($chain['execute']) {
            return $run();
        }
        if ($chain['block']) {
            throw ValidationException::withMessages(['approval' => [$chain['block']]]);
        }

        $open = OfficeApprovalRequest::query()
            ->open()
            ->where('action_key', $actionKey)
            ->when(
                $subject,
                fn ($query) => $query
                    ->where('subject_type', $subject->getMorphClass())
                    ->where('subject_id', $subject->getKey()),
                fn ($query) => $query->whereNull('subject_type')->whereNull('subject_id'),
            )
            ->first();
        if ($open) {
            throw ValidationException::withMessages([
                'approval' => ['This action is already waiting for office approval.'],
            ]);
        }

        $request = OfficeApprovalRequest::query()->create([
            'office_department_id' => $department->id,
            'office_unit_id' => $chain['unit_id'],
            'requester_user_id' => $user->id,
            'action_key' => $actionKey,
            'nav_key' => $navKey,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => array_merge($payload, [
                '_actor_user_id' => $user->id,
                '_approval_chain' => $owner['approval_chain'],
            ]),
            'summary' => $summary,
            'status' => $chain['status'],
        ]);

        $request = $request->fresh(['department.headStaff.user', 'unit.headStaff.user', 'requester']);
        $this->notifyReviewers($request);

        return $this->pendingResponse($request);
    }

    public function decide(OfficeApprovalRequest $request, User $actor, string $decision, ?string $comment = null): mixed
    {
        abort_unless($request->isOpen(), 422, 'This approval request is no longer open.');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);

        $chain = $this->requestApprovalChain($request);

        if ($request->status === OfficeApprovalRequest::PENDING_UNIT_HEAD) {
            $isUnitHead = $this->isUnitHeadFor($actor, $request);
            $isHod = $this->isHodFor($actor, $request);
            $isSuper = $this->isSuperAdmin($actor);
            abort_unless($isUnitHead || $isHod || $isSuper, 403, 'Only the unit head or head of department can review this request.');

            // HOD seniority: approve/reject while still pending unit head → final.
            if (($isHod || $isSuper) && ! $isUnitHead) {
                $request->update([
                    'hod_reviewed_by' => $actor->id,
                    'hod_reviewed_at' => now(),
                    'hod_comment' => $comment ?: 'Approved by department head (seniority override).',
                    'unit_comment' => $request->unit_comment ?: 'Skipped — department head acted first.',
                    'status' => $decision === 'reject' ? OfficeApprovalRequest::REJECTED : OfficeApprovalRequest::APPROVED,
                ]);
                $request = $request->fresh(['requester', 'department', 'unit']);
                if ($decision === 'reject') {
                    $this->notifyRequester($request, false, $comment);

                    return $this->serialize($request);
                }

                return $this->execute($request);
            }

            $request->update([
                'unit_reviewed_by' => $actor->id,
                'unit_reviewed_at' => now(),
                'unit_comment' => $comment,
                'status' => $decision === 'reject'
                    ? OfficeApprovalRequest::REJECTED
                    : (
                        $chain === OfficeNavLink::CHAIN_UNIT_HEAD
                            ? OfficeApprovalRequest::APPROVED
                            : OfficeApprovalRequest::PENDING_HOD
                    ),
            ]);
            $request = $request->fresh(['requester', 'department.headStaff.user', 'unit']);
            if ($decision === 'reject') {
                $this->notifyRequester($request, false, $comment);

                return $this->serialize($request);
            }
            if ($chain === OfficeNavLink::CHAIN_UNIT_HEAD) {
                return $this->execute($request);
            }
            if (! $request->department?->head_staff_id) {
                return $this->execute($request);
            }
            $this->notifyHod($request);

            return $this->serialize($request);
        }

        abort_unless($this->isHodFor($actor, $request) || $this->isSuperAdmin($actor), 403, 'Only the head of department can review this request.');
        $request->update([
            'hod_reviewed_by' => $actor->id,
            'hod_reviewed_at' => now(),
            'hod_comment' => $comment,
            'status' => $decision === 'reject' ? OfficeApprovalRequest::REJECTED : OfficeApprovalRequest::APPROVED,
        ]);
        $request = $request->fresh(['requester', 'department', 'unit']);
        if ($decision === 'reject') {
            $this->notifyRequester($request, false, $comment);

            return $this->serialize($request);
        }

        return $this->execute($request);
    }

    public function execute(OfficeApprovalRequest $request): mixed
    {
        self::$replaying = true;
        try {
            $result = $this->executor->run($request->action_key, $request->payload ?? []);
            $request->update([
                'status' => OfficeApprovalRequest::APPROVED,
                'executed_at' => now(),
                'error_message' => null,
            ]);
            $this->notifyRequester($request->fresh(['requester', 'department']), true);

            return $result;
        } catch (\Throwable $e) {
            $request->update(['error_message' => $e->getMessage()]);
            throw $e;
        } finally {
            self::$replaying = false;
        }
    }

    public function inbox(User $user, ?string $scope = null)
    {
        $query = OfficeApprovalRequest::query()
            ->with(['requester:id,name,email', 'department:id,name,code,head_staff_id', 'unit:id,name,code,head_staff_id'])
            ->latest('id');

        $scope ??= $this->isReviewer($user) ? 'review' : 'submitted';

        $page = match ($scope) {
            'submitted' => $query->where('requester_user_id', $user->id)->paginate(20),
            'decided' => $query
                ->whereIn('status', [OfficeApprovalRequest::APPROVED, OfficeApprovalRequest::REJECTED, OfficeApprovalRequest::CANCELLED])
                ->when(! $this->isSuperAdmin($user), function ($inner) use ($user) {
                    $staffId = $user->staff?->id;
                    $inner->where(function ($q) use ($user, $staffId) {
                        $q->where('requester_user_id', $user->id)
                            ->orWhere('unit_reviewed_by', $user->id)
                            ->orWhere('hod_reviewed_by', $user->id);
                        if ($staffId) {
                            $q->orWhereHas('department', fn ($d) => $d->where('head_staff_id', $staffId))
                                ->orWhereHas('unit', fn ($u) => $u->where('head_staff_id', $staffId));
                        }
                    });
                })
                ->paginate(20),
            default => $query
                ->open()
                ->when(! $this->isSuperAdmin($user), fn ($inner) => $this->constrainReviewQueue($inner, $user))
                ->paginate(20),
        };

        $page->getCollection()->transform(fn (OfficeApprovalRequest $row) => $this->serialize($row, $user));

        return $page;
    }

    public function serialize(OfficeApprovalRequest $request, ?User $viewer = null): array
    {
        $request->loadMissing(['requester:id,name,email', 'department:id,name,code,head_staff_id', 'unit:id,name,code,head_staff_id']);

        return [
            'id' => $request->id,
            'action_key' => $request->action_key,
            'action_label' => OfficeApprovalCatalog::label($request->action_key),
            'nav_key' => $request->nav_key,
            'summary' => $request->summary,
            'status' => $request->status,
            'payload' => $request->payload,
            'approval_chain' => $this->requestApprovalChain($request),
            'office_department' => $request->department ? [
                'id' => $request->department->id,
                'name' => $request->department->name,
            ] : null,
            'office_unit' => $request->unit ? [
                'id' => $request->unit->id,
                'name' => $request->unit->name,
            ] : null,
            'requester' => $request->requester ? [
                'id' => $request->requester->id,
                'name' => $request->requester->name,
                'email' => $request->requester->email,
            ] : null,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'unit_comment' => $request->unit_comment,
            'hod_comment' => $request->hod_comment,
            'unit_reviewed_at' => $request->unit_reviewed_at,
            'hod_reviewed_at' => $request->hod_reviewed_at,
            'error_message' => $request->error_message,
            'created_at' => $request->created_at,
            'executed_at' => $request->executed_at,
            'can_review' => $viewer ? $this->canReview($request, $viewer) : false,
        ];
    }

    public function canReview(OfficeApprovalRequest $request, User $user): bool
    {
        if (! $request->isOpen()) {
            return false;
        }
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        if ($request->status === OfficeApprovalRequest::PENDING_UNIT_HEAD) {
            return $this->isUnitHeadFor($user, $request) || $this->isHodFor($user, $request);
        }

        return $this->isHodFor($user, $request);
    }

    public function openFor(Model $subject, ?string $actionKey = null): ?OfficeApprovalRequest
    {
        return OfficeApprovalRequest::query()
            ->open()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->when($actionKey, fn ($q) => $q->where('action_key', $actionKey))
            ->latest('id')
            ->first();
    }

    public function openKeyedBySubject($subjects)
    {
        $subjects = collect($subjects);
        if ($subjects->isEmpty()) {
            return collect();
        }
        $first = $subjects->first();

        return OfficeApprovalRequest::query()
            ->open()
            ->where('subject_type', $first->getMorphClass())
            ->whereIn('subject_id', $subjects->map->getKey()->all())
            ->get()
            ->keyBy('subject_id');
    }

    public function isReviewer(User $user): bool
    {
        return $this->isSuperAdmin($user)
            || $this->headedDepartment($user) !== null
            || $this->headedUnit($user) !== null;
    }

    public function headedDepartment(User $user): ?OfficeDepartment
    {
        $staffId = $user->staff?->id;

        return $staffId
            ? OfficeDepartment::query()->where('head_staff_id', $staffId)->first()
            : null;
    }

    public function headedUnit(User $user): ?OfficeUnit
    {
        $staffId = $user->staff?->id;

        return $staffId
            ? OfficeUnit::query()->where('head_staff_id', $staffId)->first()
            : null;
    }

    private function pendingResponse(OfficeApprovalRequest $request): JsonResponse
    {
        $step = $request->status === OfficeApprovalRequest::PENDING_UNIT_HEAD
            ? 'unit head'
            : 'head of department';

        return response()->json([
            'status' => 'pending_approval',
            'message' => "Sent for {$step} approval.",
            'approval_request' => $this->serialize($request),
        ], 202);
    }

    /**
     * @param  array{require_create: bool, require_update: bool, require_delete: bool}  $owner
     */
    private function mutationRequiresApproval(array $owner, string $mutation): bool
    {
        return match ($mutation) {
            OfficeApprovalCatalog::MUTATION_CREATE => (bool) $owner['require_create'],
            OfficeApprovalCatalog::MUTATION_DELETE => (bool) $owner['require_delete'],
            default => (bool) $owner['require_update'],
        };
    }

    /**
     * @param  array{department: ?OfficeDepartment, unit: ?OfficeUnit, subunit: ?OfficeSubunit}  $placement
     * @return array{execute: bool, block: ?string, status: ?string, unit_id: ?int}
     */
    private function chainFor(User $user, OfficeDepartment $department, array $placement, string $approvalChain): array
    {
        if ($this->isSuperAdmin($user) || $this->isDepartmentHead($user, $department)) {
            return ['execute' => true, 'block' => null, 'status' => null, 'unit_id' => null];
        }

        $unit = $placement['unit'];
        $isSubunitStaff = $placement['subunit'] !== null;
        $isUnitHead = $unit && $user->staff && (int) $unit->head_staff_id === (int) $user->staff->id;
        $unitId = $unit?->id;

        if ($approvalChain === OfficeNavLink::CHAIN_DEPARTMENT_HEAD) {
            if (! $department->head_staff_id) {
                return ['execute' => true, 'block' => null, 'status' => null, 'unit_id' => $unitId];
            }

            return [
                'execute' => false,
                'block' => null,
                'status' => OfficeApprovalRequest::PENDING_HOD,
                'unit_id' => $unitId,
            ];
        }

        // unit_head or both — may need unit head first
        $needsUnitStep = false;
        if ($isSubunitStaff) {
            if (! $unit?->head_staff_id) {
                return [
                    'execute' => false,
                    'block' => 'Assign a unit head before subunit staff can submit work for approval.',
                    'status' => null,
                    'unit_id' => $unit?->id,
                ];
            }
            if (! $isUnitHead) {
                $needsUnitStep = true;
            }
        } elseif ($unit && $unit->head_staff_id && ! $isUnitHead) {
            $needsUnitStep = true;
        }

        if ($needsUnitStep) {
            return [
                'execute' => false,
                'block' => null,
                'status' => OfficeApprovalRequest::PENDING_UNIT_HEAD,
                'unit_id' => $unit->id,
            ];
        }

        // Actor is unit head or department staff without a unit-head step
        if ($approvalChain === OfficeNavLink::CHAIN_UNIT_HEAD) {
            // No further reviewer required for this actor
            return ['execute' => true, 'block' => null, 'status' => null, 'unit_id' => $unitId];
        }

        // both — escalate to HOD when present
        if ($department->head_staff_id) {
            return [
                'execute' => false,
                'block' => null,
                'status' => OfficeApprovalRequest::PENDING_HOD,
                'unit_id' => $unitId,
            ];
        }

        return ['execute' => true, 'block' => null, 'status' => null, 'unit_id' => $unitId];
    }

    private function requestApprovalChain(OfficeApprovalRequest $request): string
    {
        $fromPayload = $request->payload['_approval_chain'] ?? null;
        if (is_string($fromPayload) && in_array($fromPayload, OfficeNavLink::CHAINS, true)) {
            return $fromPayload;
        }
        $owner = $this->owners->ownerForNavKey((string) $request->nav_key);

        return $owner['approval_chain'] ?? OfficeNavLink::CHAIN_BOTH;
    }

    /**
     * @return array{department: ?OfficeDepartment, unit: ?OfficeUnit, subunit: ?OfficeSubunit}
     */
    private function actorPlacement(User $user): array
    {
        $staff = $user->staff;
        if (! $staff) {
            return ['department' => null, 'unit' => null, 'subunit' => null];
        }

        $subunit = $staff->office_subunit_id
            ? OfficeSubunit::query()->with('unit.department')->find($staff->office_subunit_id)
            : null;
        $unit = $subunit?->unit
            ?? ($staff->office_unit_id ? OfficeUnit::query()->with('department')->find($staff->office_unit_id) : null);
        $department = $unit?->department
            ?? ($staff->office_department_id ? OfficeDepartment::query()->find($staff->office_department_id) : null);

        return ['department' => $department, 'unit' => $unit, 'subunit' => $subunit];
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('slug', 'super-admin')->where('is_active', true)->exists();
    }

    private function isDepartmentHead(User $user, OfficeDepartment $department): bool
    {
        return $user->staff && (int) $department->head_staff_id === (int) $user->staff->id;
    }

    private function isUnitHeadFor(User $actor, OfficeApprovalRequest $request): bool
    {
        $staffId = $actor->staff?->id;
        if (! $staffId || ! $request->office_unit_id) {
            return false;
        }
        $unit = $request->unit ?? OfficeUnit::query()->find($request->office_unit_id);

        return $unit && (int) $unit->head_staff_id === (int) $staffId;
    }

    private function isHodFor(User $actor, OfficeApprovalRequest $request): bool
    {
        $staffId = $actor->staff?->id;
        if (! $staffId) {
            return false;
        }
        $department = $request->department ?? OfficeDepartment::query()->find($request->office_department_id);

        return $department && (int) $department->head_staff_id === (int) $staffId;
    }

    private function constrainReviewQueue($query, User $user)
    {
        $staffId = $user->staff?->id;
        abort_unless($staffId, 403, 'You do not have an office approval queue.');

        return $query->where(function ($inner) use ($staffId) {
            $inner->where(function ($unitQ) use ($staffId) {
                $unitQ->where('status', OfficeApprovalRequest::PENDING_UNIT_HEAD)
                    ->whereHas('unit', fn ($u) => $u->where('head_staff_id', $staffId));
            })->orWhere(function ($hodPending) use ($staffId) {
                $hodPending->where('status', OfficeApprovalRequest::PENDING_HOD)
                    ->whereHas('department', fn ($d) => $d->where('head_staff_id', $staffId));
            })->orWhere(function ($hodSeniority) use ($staffId) {
                // HOD seniority: also see pending unit-head items for their department
                $hodSeniority->where('status', OfficeApprovalRequest::PENDING_UNIT_HEAD)
                    ->whereHas('department', fn ($d) => $d->where('head_staff_id', $staffId));
            });
        });
    }

    private function notifyReviewers(OfficeApprovalRequest $request): void
    {
        if ($request->status === OfficeApprovalRequest::PENDING_UNIT_HEAD) {
            $user = $request->unit?->headStaff?->user;
            if ($user) {
                $this->notifier->send($user, 'office_approval', 'Unit approval needed', $request->summary, 'approvals', $request->id);
            }
            // HOD also notified so they can exercise seniority
            $this->notifyHod($request);

            return;
        }
        $this->notifyHod($request);
    }

    private function notifyHod(OfficeApprovalRequest $request): void
    {
        $user = $request->department?->headStaff?->user;
        if ($user) {
            $this->notifier->send($user, 'office_approval', 'HOD approval needed', $request->summary, 'approvals', $request->id);
        }
    }

    private function notifyRequester(OfficeApprovalRequest $request, bool $approved, ?string $comment = null): void
    {
        if (! $request->requester) {
            return;
        }
        $this->notifier->send(
            $request->requester,
            'office_approval',
            $approved ? 'Office request approved' : 'Office request rejected',
            $comment ?: $request->summary,
            'approvals',
            $request->id,
        );
    }
}
