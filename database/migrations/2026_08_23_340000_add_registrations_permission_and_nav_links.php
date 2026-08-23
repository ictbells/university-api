<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ADMISSIONS_TO_REGISTRATIONS = [
        'admissions-undergraduate' => 'registrations-undergraduate',
        'admissions-jupeb' => 'registrations-jupeb',
        'admissions-postgraduate' => 'registrations-postgraduate',
    ];

    public function up(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $permissionId = Permission::query()->where('key', 'registrations.view')->value('id');
        if ($permissionId) {
            $admissionsRoles = Role::query()
                ->whereHas('permissions', fn ($q) => $q->where('key', 'admissions.view'))
                ->pluck('id');

            foreach ($admissionsRoles as $roleId) {
                Role::query()->find($roleId)?->permissions()->syncWithoutDetaching([$permissionId]);
            }
        }

        foreach (self::ADMISSIONS_TO_REGISTRATIONS as $admissionsKey => $registrationsKey) {
            $rows = DB::table('office_nav_links')->where('nav_key', $admissionsKey)->get();
            foreach ($rows as $row) {
                DB::table('office_nav_links')->updateOrInsert(
                    [
                        'linkable_type' => $row->linkable_type,
                        'linkable_id' => $row->linkable_id,
                        'nav_key' => $registrationsKey,
                    ],
                    [
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('office_nav_links')
            ->whereIn('nav_key', array_values(self::ADMISSIONS_TO_REGISTRATIONS))
            ->delete();
    }
};
