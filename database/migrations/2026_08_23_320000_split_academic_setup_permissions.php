<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AcademicResourceCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        $institutionRoles = Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('key', 'institution.manage'))
            ->pluck('id');

        $catalogRoles = Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('key', 'academic.catalog.manage'))
            ->pluck('id');

        $institutionResourceKeys = ['campuses', 'colleges', 'departments', 'sessions', 'levels', 'intakes', 'olevel'];
        $catalogResourceKeys = ['programmes', 'courses'];

        foreach ($institutionResourceKeys as $key) {
            $permissionId = Permission::query()->where('key', AcademicResourceCatalog::permission($key))->value('id');
            if (! $permissionId) {
                continue;
            }
            foreach ($institutionRoles as $roleId) {
                Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
            }
        }

        foreach ($catalogResourceKeys as $key) {
            $permissionId = Permission::query()->where('key', AcademicResourceCatalog::permission($key))->value('id');
            if (! $permissionId) {
                continue;
            }
            foreach ($catalogRoles as $roleId) {
                Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
            }
        }
    }

    public function down(): void
    {
        // Permissions remain; role grants are left in place.
    }
};
