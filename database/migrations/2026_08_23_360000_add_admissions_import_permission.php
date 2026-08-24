<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $permissionId = Permission::query()->where('key', 'admissions.import')->value('id');
        if (! $permissionId) {
            return;
        }

        $roleIds = Role::query()
            ->whereHas('permissions', fn ($q) => $q->whereIn('key', ['admissions.view', 'institution.manage']))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
        }

        $admissionsNavKeys = [
            'admissions-undergraduate',
            'admissions-jupeb',
            'admissions-postgraduate',
        ];

        $rows = DB::table('office_nav_links')
            ->whereIn('nav_key', $admissionsNavKeys)
            ->get();

        foreach ($rows as $row) {
            DB::table('office_nav_links')->updateOrInsert(
                [
                    'linkable_type' => $row->linkable_type,
                    'linkable_id' => $row->linkable_id,
                    'nav_key' => 'candidate-data',
                ],
                [
                    'created_at' => $row->created_at,
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        // Permission remains; role grants are left in place.
    }
};
