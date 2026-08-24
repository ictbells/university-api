<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $keys = [
            'results.read',
            'results.write',
            'results.submit',
            'results.faculty_approve',
            'results.board',
            'results.release',
            'results.import',
            'scales.manage',
        ];

        foreach (PermissionCatalog::all() as $perm) {
            if (! in_array($perm['key'], $keys, true)) {
                continue;
            }
            $existing = DB::table('permissions')->where('key', $perm['key'])->first();
            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update([
                    'module' => $perm['module'],
                    'label' => $perm['label'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('permissions')->insert([
                    'key' => $perm['key'],
                    'module' => $perm['module'],
                    'label' => $perm['label'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $roleIds = DB::table('roles')->whereIn('slug', ['super-admin', 'registrar'])->pluck('id');
        $permIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permIds as $permId) {
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
        }
    }

    public function down(): void
    {
        $keys = [
            'results.read',
            'results.write',
            'results.submit',
            'results.faculty_approve',
            'results.board',
            'results.release',
            'results.import',
            'scales.manage',
        ];
        $permIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('key', $keys)->delete();
    }
};
