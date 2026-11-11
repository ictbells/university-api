<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $roles = [
            'lecturer' => [
                'name' => 'Lecturer',
                'description' => 'Enter and upload draft scores only for course offerings assigned to them.',
                'keys' => [
                    'results.read', 'results.write', 'results.import',
                ],
            ],
            'department-hod' => [
                'name' => 'Department HOD',
                'description' => 'View and process all results for their academic department, including Department submit to College.',
                'keys' => [
                    'results.read', 'results.write', 'results.import', 'results.department_submit',
                ],
            ],
            'college-dean' => [
                'name' => 'College Dean',
                'description' => 'View and process all results for their college, including College submit to the Committee of Deans.',
                'keys' => [
                    'results.read', 'results.write', 'results.import', 'results.department_submit', 'results.submit',
                ],
            ],
            'committee-of-deans' => [
                'name' => 'Committee of Deans',
                'description' => 'Review and approve or return college submissions across all colleges.',
                'keys' => [
                    'results.read', 'results.faculty_approve',
                ],
            ],
        ];

        foreach ($roles as $slug => $meta) {
            $existing = DB::table('roles')->where('slug', $slug)->first();
            if ($existing) {
                DB::table('roles')->where('id', $existing->id)->update([
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
                $roleId = (int) $existing->id;
            } else {
                $roleId = (int) DB::table('roles')->insertGetId([
                    'name' => $meta['name'],
                    'slug' => $slug,
                    'description' => $meta['description'],
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permIds = DB::table('permissions')->whereIn('key', $meta['keys'])->pluck('id');
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
        $slugs = ['lecturer', 'department-hod', 'college-dean', 'committee-of-deans'];
        $roleIds = DB::table('roles')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('slug', $slugs)->delete();
    }
};
