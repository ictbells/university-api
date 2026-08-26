<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private AuditWriter $audit) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
        ]);

        return Role::query()
            ->with('permissions')
            ->withCount('users')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();
    }

    public function permissions(Request $request)
    {
        if ($request->boolean('grouped')) {
            return Permission::query()->orderBy('module')->orderBy('label')->get()->groupBy('module');
        }

        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'module' => 'nullable|string|max:60',
        ]);

        return Permission::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('key', 'like', $term)
                        ->orWhere('label', 'like', $term)
                        ->orWhere('module', 'like', $term);
                });
            })
            ->when($filters['module'] ?? null, fn ($query, string $module) => $query->where('module', $module))
            ->orderBy('module')
            ->orderBy('label')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'permission_ids' => 'array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        return $this->officeGate('roles.store', null, $data, 'Create role '.$data['name'], function () use ($data) {
            $role = Role::query()->create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_active' => true,
            ]);
            $role->permissions()->sync($data['permission_ids'] ?? []);
            $this->audit->record('role.created', 'Created role '.$role->name, 'users', 'role', $role->id, null, $role->load('permissions'));

            return response()->json($role->load('permissions'), 201);
        });
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('roles.update', $role, ['role_id' => $role->id, ...$data], 'Update role '.$role->name, function () use ($role, $data) {
            $before = $this->roleAuditSnapshot($role);
            $role->update($data);
            $this->audit->record('role.updated', 'Updated role '.$role->name, 'users', 'role', $role->id, $before, $this->roleAuditSnapshot($role->fresh('permissions')));

            return $role->fresh('permissions');
        });
    }

    public function destroy(Request $request, Role $role)
    {
        if ($role->slug === 'super-admin') {
            return response()->json(['message' => 'The Super Admin role cannot be deleted.'], 422);
        }

        $actor = $request->user();
        $isSuperAdmin = $actor?->roles()
            ->where('slug', 'super-admin')
            ->where('is_active', true)
            ->exists();

        if ($role->is_system && ! $isSuperAdmin) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }

        $assignedUsers = $role->users()->count();
        if ($assignedUsers > 0) {
            return response()->json([
                'message' => "This role is assigned to {$assignedUsers} user(s). Remove it from those accounts before deleting.",
            ], 422);
        }

        return $this->officeGate(
            'roles.destroy',
            $role,
            ['role_id' => $role->id, 'reason' => $request->input('reason', 'Role removed')],
            'Delete role '.$role->name,
            function () use ($request, $role) {
                $before = $role->toArray();
                $role->permissions()->detach();
                $role->delete();
                $this->audit->record('role.deleted', 'Deleted role', 'users', 'role', $role->id, $before, null, $request->input('reason', 'Role removed'));

                return response()->json(['message' => 'Deleted']);
            },
        );
    }

    public function syncPermissions(Request $request, Role $role)
    {
        $data = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'exists:permissions,id',
            'reason' => 'nullable|string',
        ]);

        return $this->officeGate(
            'roles.sync_permissions',
            $role,
            ['role_id' => $role->id, ...$data],
            'Update permissions for '.$role->name,
            function () use ($role, $data) {
                $before = $role->permissions->pluck('key')->sort()->values()->all();
                $role->permissions()->sync($data['permission_ids']);
                $after = $role->fresh('permissions')->permissions->pluck('key')->sort()->values()->all();
                $this->audit->record('role.permissions', 'Updated role permissions', 'users', 'role', $role->id, ['permissions' => $before], ['permissions' => $after], $data['reason'] ?? 'Permission ticks updated');

                return $role->fresh('permissions');
            },
        );
    }

    private function roleAuditSnapshot(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions->pluck('key')->sort()->values()->all(),
        ];
    }
}
