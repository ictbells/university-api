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

        $keys = [
            'academic.offerings.manage',
            'academic.enrollments.manage',
            'academic.enrollments.grace',
            'academic.extensions.review',
        ];
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
        $academicRoles = Role::query()
            ->where('is_system', true)
            ->whereIn('slug', ['super-admin', 'registrar'])
            ->get();
        foreach ($academicRoles as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('key', [
            'academic.offerings.manage',
            'academic.enrollments.manage',
            'academic.enrollments.grace',
            'academic.extensions.review',
        ])->delete();
    }
};
