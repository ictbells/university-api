<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OfficeApprovalService;
use App\Services\StaffOfficePlacement;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private AuditWriter $audit, private StaffOfficePlacement $placement) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|in:active,disabled',
            'office_department_id' => 'nullable|integer|exists:office_departments,id',
            'office_unit_id' => 'nullable|integer|exists:office_units,id',
        ]);

        $users = User::query()
            ->staffPortal()
            ->with(['roles', 'staff'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['office_department_id'] ?? null, fn ($query, int $departmentId) => $query->whereHas(
                'staff',
                fn ($staff) => $staff->where('office_department_id', $departmentId),
            ))
            ->when($filters['office_unit_id'] ?? null, fn ($query, int $unitId) => $query->whereHas(
                'staff',
                fn ($staff) => $staff->where('office_unit_id', $unitId),
            ))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();
        $users->getCollection()->transform(function (User $user) {
            $this->placement->enrich($user);

            return $user;
        });

        return $users;
    }

    public function store(Request $request)
    {
        $replaying = OfficeApprovalService::$replaying;
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'password' => $replaying ? ['required', 'string'] : PasswordRules::rules(),
            'status' => 'nullable|in:active,disabled',
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
            'staff_title' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'office_department_id' => 'nullable|exists:office_departments,id',
            'office_unit_id' => 'nullable|exists:office_units,id',
            'office_subunit_id' => 'nullable|exists:office_subunits,id',
        ], $replaying ? [] : PasswordRules::messages());

        if (! $replaying) {
            $data['password'] = Hash::make($data['password']);
        }

        return $this->officeGate('users.store', null, $data, 'Create user '.$data['email'], function () use ($request, $data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => $data['status'] ?? 'active',
                'password_changed_at' => now(),
            ]);
            if (! empty($data['role_ids'])) {
                $user->roles()->sync($data['role_ids']);
            }
            if ($request->filled('staff_title')
                || $request->filled('department_id')
                || $request->filled('office_department_id')
                || $request->filled('office_unit_id')
                || $request->filled('office_subunit_id')) {
                Staff::query()->create([
                    'user_id' => $user->id,
                    'department_id' => $data['department_id'] ?? null,
                    'office_department_id' => $data['office_department_id'] ?? null,
                    'office_unit_id' => $data['office_unit_id'] ?? null,
                    'office_subunit_id' => $data['office_subunit_id'] ?? null,
                    'staff_number' => 'STF-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                    'title' => $data['staff_title'] ?? 'Staff',
                ]);
            }
            $this->audit->record('user.created', 'Created user '.$user->email, 'users', 'user', $user->id, null, $this->userAuditSnapshot($user->fresh(['roles', 'staff'])));

            $user = $user->load(['roles', 'staff']);
            $this->placement->enrich($user);

            return response()->json($user, 201);
        });
    }

    public function update(Request $request, User $user)
    {
        $replaying = OfficeApprovalService::$replaying;
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
            'password' => $replaying ? ['nullable', 'string'] : PasswordRules::rules(false),
            'status' => 'nullable|in:active,disabled',
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
            'staff_title' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'office_department_id' => 'nullable|exists:office_departments,id',
            'office_unit_id' => 'nullable|exists:office_units,id',
            'office_subunit_id' => 'nullable|exists:office_subunits,id',
            'clear_office_placement' => 'nullable|boolean',
        ], $replaying ? [] : PasswordRules::messages());

        if (empty($data['password'])) {
            unset($data['password']);
        } elseif (! $replaying) {
            $data['password'] = Hash::make($data['password']);
            $data['password_changed_at'] = now()->toIso8601String();
        }
        $reason = $request->input('reason');
        if (isset($data['status']) && $data['status'] === 'disabled' && ! $reason) {
            return response()->json(['message' => 'A reason is required to disable a user.'], 422);
        }
        if ($request->has('role_ids')) {
            if ($user->roles()->where('slug', 'super-admin')->exists()
                && ! Role::query()->whereIn('id', $data['role_ids'] ?? [])->where('slug', 'super-admin')->exists()
                && User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))->where('id', '!=', $user->id)->count() < 1) {
                return response()->json(['message' => 'The last Super Admin cannot be stripped of that role.'], 422);
            }
        }

        return $this->officeGate(
            'users.update',
            $user,
            ['user_id' => $user->id, 'reason' => $reason, ...$data],
            'Update user '.$user->email,
            function () use ($request, $user, $data, $reason) {
                $before = $this->userAuditSnapshot($user);
                $user->fill(collect($data)->except([
                    'role_ids',
                    'staff_title',
                    'department_id',
                    'office_department_id',
                    'office_unit_id',
                    'office_subunit_id',
                    'clear_office_placement',
                ])->all())->save();
                if ($request->has('role_ids')) {
                    $user->roles()->sync($data['role_ids'] ?? []);
                }

                $this->syncStaff($user, $request, $data);

                $this->audit->record('user.updated', 'Updated user '.$user->email, 'users', 'user', $user->id, $before, $this->userAuditSnapshot($user->fresh(['roles', 'staff'])), $reason);

                $user = $user->fresh(['roles', 'staff', 'student']);
                $this->placement->enrich($user);

                return $user;
            },
        );
    }

    public function destroy(Request $request, User $user)
    {
        if (! $user->isStaffPortalUser()) {
            return response()->json(['message' => 'Only staff accounts can be removed here.'], 422);
        }
        if ($user->roles()->where('slug', 'super-admin')->exists()
            && User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))->count() <= 1) {
            return response()->json(['message' => 'The last Super Admin cannot be deleted.'], 422);
        }

        return $this->officeGate(
            'users.destroy',
            $user,
            ['user_id' => $user->id, 'reason' => $request->input('reason', 'User removed')],
            'Delete user '.$user->email,
            function () use ($request, $user) {
                $before = $this->userAuditSnapshot($user);
                $user->delete();
                $this->audit->record('user.deleted', 'Deleted user', 'users', 'user', $user->id, $before, null, $request->input('reason', 'User removed'));

                return response()->json(['message' => 'Deleted']);
            },
        );
    }

    public function assignRoles(Request $request, User $user)
    {
        $data = $request->validate([
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
            'reason' => 'required|string|min:3',
        ]);

        return $this->officeGate(
            'users.assign_roles',
            $user,
            ['user_id' => $user->id, ...$data],
            'Assign roles for '.$user->email,
            function () use ($user, $data) {
                $before = $user->roles->pluck('id');
                $user->roles()->sync($data['role_ids']);
                $this->audit->record('user.roles', 'Roles assigned', 'users', 'user', $user->id, $before, $user->fresh()->roles->pluck('id'), $data['reason']);

                return $user->fresh('roles');
            },
        );
    }

    private function userAuditSnapshot(User $user): array
    {
        $user->loadMissing(['roles', 'staff']);

        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'staff_title' => $user->staff?->title,
            'department_id' => $user->staff?->department_id,
            'office_department_id' => $user->staff?->office_department_id,
            'office_unit_id' => $user->staff?->office_unit_id,
            'office_subunit_id' => $user->staff?->office_subunit_id,
        ];
    }

    private function syncStaff(User $user, Request $request, array $data): void
    {
        $hasOfficeInput = $request->boolean('clear_office_placement')
            || $request->hasAny(['office_department_id', 'office_unit_id', 'office_subunit_id']);
        $hasStaffInput = $request->has('staff_title') || $hasOfficeInput || $request->filled('department_id');

        if (! $hasStaffInput) {
            return;
        }

        $payload = [];
        if ($request->has('staff_title')) {
            $payload['title'] = $data['staff_title'] ?? 'Staff';
        }
        if ($request->has('department_id')) {
            $payload['department_id'] = $data['department_id'] ?? null;
        }
        if ($request->boolean('clear_office_placement')) {
            $payload['office_department_id'] = null;
            $payload['office_unit_id'] = null;
            $payload['office_subunit_id'] = null;
        } elseif ($hasOfficeInput) {
            $payload['office_department_id'] = $request->input('office_department_id') ?: null;
            $payload['office_unit_id'] = $request->input('office_unit_id') ?: null;
            $payload['office_subunit_id'] = $request->input('office_subunit_id') ?: null;
        }

        if ($user->staff) {
            if ($payload !== []) {
                $user->staff->update($payload);
            }

            return;
        }

        Staff::query()->create([
            'user_id' => $user->id,
            'department_id' => $payload['department_id'] ?? null,
            'office_department_id' => $payload['office_department_id'] ?? null,
            'office_unit_id' => $payload['office_unit_id'] ?? null,
            'office_subunit_id' => $payload['office_subunit_id'] ?? null,
            'staff_number' => 'STF-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
            'title' => $payload['title'] ?? 'Staff',
        ]);
    }
}
