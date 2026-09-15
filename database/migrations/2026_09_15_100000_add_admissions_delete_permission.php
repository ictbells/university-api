<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $permissionId = Permission::query()->where('key', 'admissions.delete')->value('id');
        if (! $permissionId) {
            return;
        }

        $roleIds = Role::query()
            ->where(function ($query) {
                $query->where('slug', 'super-admin')
                    ->orWhereHas('permissions', fn ($q) => $q->where('key', 'admissions.view'));
            })
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
        }
    }

    public function down(): void
    {
        // Permission remains; role grants are left in place.
    }
};
