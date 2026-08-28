<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $roles = [
            'exam-officer' => [
                'name' => 'Exam Officer',
                'description' => 'Campus-wide exam officer: enter, submit through Department, College, Committee of Deans, Senate, and release results.',
                'keys' => [
                    'results.read', 'results.write', 'results.department_submit', 'results.submit', 'results.faculty_approve',
                    'results.board', 'results.release', 'results.import', 'scales.manage',
                ],
            ],
            'faculty-exam-officer' => [
                'name' => 'College Exam Officer',
                'description' => 'Reviews department submissions in their college and submits them to the Committee of Deans.',
                'keys' => [
                    'results.read', 'results.write', 'results.department_submit', 'results.submit', 'results.faculty_approve', 'results.import',
                ],
            ],
            'department-exam-officer' => [
                'name' => 'Department Exam Officer',
                'description' => 'Uploads departmental courses and submits them to College.',
                'keys' => [
                    'results.read', 'results.write', 'results.department_submit', 'results.import',
                ],
            ],
            'gs-exam-officer' => [
                'name' => 'GS Exam Officer',
                'description' => 'Uploads general-studies courses only.',
                'keys' => [
                    'results.read', 'results.write', 'results.department_submit', 'results.submit', 'results.import',
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
                $roleId = DB::table('roles')->insertGetId([
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
        $slugs = ['exam-officer', 'faculty-exam-officer', 'department-exam-officer', 'gs-exam-officer'];
        $roleIds = DB::table('roles')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('slug', $slugs)->delete();
    }
};
