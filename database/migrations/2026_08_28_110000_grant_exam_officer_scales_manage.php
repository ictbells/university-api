<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'exam-officer')->value('id');
        $permId = DB::table('permissions')->where('key', 'scales.manage')->value('id');
        if (! $roleId || ! $permId) {
            return;
        }

        $exists = DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->exists();
        if (! $exists) {
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'exam-officer')->value('id');
        $permId = DB::table('permissions')->where('key', 'scales.manage')->value('id');
        if (! $roleId || ! $permId) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->delete();
    }
};
