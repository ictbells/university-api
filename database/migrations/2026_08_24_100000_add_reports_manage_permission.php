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

        $manageId = Permission::query()->where('key', 'reports.manage')->value('id');
        if (! $manageId) {
            return;
        }

        $roleIds = Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('key', 'reports.view'))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$manageId]);
        }
    }

    public function down(): void
    {
        Permission::query()->where('key', 'reports.manage')->delete();
    }
};
