<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $query = DB::table('permissions')->where('key', 'resources.view');
        if (Schema::hasColumn('permissions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $permissionId = $query->value('id');
        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => 'resources.view',
                'module' => 'admin',
                'label' => 'View and download platform resources',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleQuery = DB::table('roles')->where('slug', 'super-admin');
        if (Schema::hasColumn('roles', 'deleted_at')) {
            $roleQuery->whereNull('deleted_at');
        }
        $superAdminId = $roleQuery->value('id');
        if ($superAdminId && ! DB::table('role_permissions')->where('role_id', $superAdminId)->where('permission_id', $permissionId)->exists()) {
            DB::table('role_permissions')->insert([
                'role_id' => $superAdminId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'resources.view')->delete();
    }
};
