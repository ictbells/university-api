<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (PermissionCatalog::all() as $perm) {
            if ($perm['key'] !== 'results.department_submit' && $perm['key'] !== 'results.submit') {
                continue;
            }
            $existing = DB::table('permissions')->where('key', $perm['key'])->first();
            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update([
                    'module' => $perm['module'],
                    'label' => $perm['label'],
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('permissions')->insert([
                    'key' => $perm['key'],
                    'module' => $perm['module'],
                    'label' => $perm['label'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $departmentSubmitId = DB::table('permissions')->where('key', 'results.department_submit')->value('id');
        $collegeSubmitId = DB::table('permissions')->where('key', 'results.submit')->value('id');

        if ($departmentSubmitId) {
            $roleIds = DB::table('roles')
                ->whereIn('slug', [
                    'super-admin',
                    'registrar',
                    'exam-officer',
                    'faculty-exam-officer',
                    'department-exam-officer',
                    'gs-exam-officer',
                ])
                ->pluck('id');
            if ($collegeSubmitId) {
                $roleIds = $roleIds->merge(
                    DB::table('role_permissions')->where('permission_id', $collegeSubmitId)->pluck('role_id')
                )->unique()->values();
            }
            foreach ($roleIds as $roleId) {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $departmentSubmitId)
                    ->exists();
                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $departmentSubmitId,
                    ]);
                }
            }

            $departmentOfficerIds = DB::table('roles')->where('slug', 'department-exam-officer')->pluck('id');
            if ($collegeSubmitId) {
                DB::table('role_permissions')
                    ->whereIn('role_id', $departmentOfficerIds)
                    ->where('permission_id', $collegeSubmitId)
                    ->delete();
            }
        }

        DB::table('roles')->where('slug', 'exam-officer')->update([
            'description' => 'Campus-wide exam officer: enter, submit through Department, College, Committee of Deans, Senate, and release results.',
            'updated_at' => $now,
        ]);
        DB::table('roles')->where('slug', 'faculty-exam-officer')->update([
            'description' => 'Reviews department submissions in their college and submits them to the Committee of Deans.',
            'updated_at' => $now,
        ]);
        DB::table('roles')->where('slug', 'department-exam-officer')->update([
            'description' => 'Uploads departmental courses and submits them to College.',
            'updated_at' => $now,
        ]);

        $this->copyNavKey('results-department', 'results-college');
    }

    public function down(): void
    {
        DB::table('office_nav_links')->where('nav_key', 'results-college')->delete();

        $permId = DB::table('permissions')->where('key', 'results.department_submit')->value('id');
        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }

    private function copyNavKey(string $source, string $target): void
    {
        $rows = DB::table('office_nav_links')->where('nav_key', $source)->get();
        foreach ($rows as $row) {
            DB::table('office_nav_links')->updateOrInsert(
                [
                    'linkable_type' => $row->linkable_type,
                    'linkable_id' => $row->linkable_id,
                    'nav_key' => $target,
                ],
                [
                    'created_at' => $row->created_at,
                    'updated_at' => now(),
                ],
            );
        }
    }
};
