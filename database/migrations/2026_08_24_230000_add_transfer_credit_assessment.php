<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $permissionId = Permission::query()->where('key', 'admissions.credit_assess')->value('id');
        if ($permissionId) {
            $roleIds = Role::query()
                ->whereHas('permissions', fn ($q) => $q->whereIn('key', [
                    'admissions.verify',
                    'admissions.shortlist',
                    'institution.manage',
                ]))
                ->pluck('id');
            foreach ($roleIds as $roleId) {
                Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
            }
        }

        WorkflowCatalog::seed();
    }

    public function down(): void
    {
        // Permission and workflow template remain.
    }
};
