<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            if (! str_starts_with($perm['key'], 'results.') && $perm['key'] !== 'scales.manage') {
                continue;
            }
            DB::table('permissions')->where('key', $perm['key'])->update([
                'label' => $perm['label'],
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')->where('slug', 'faculty-exam-officer')->update([
            'name' => 'College Exam Officer',
            'description' => 'Uploads college-lane courses in their college and submits them to the Committee of Deans.',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('slug', 'exam-officer')->update([
            'description' => 'Campus-wide exam officer: enter, submit through College, Committee of Deans, Senate, and release results.',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('slug', 'department-exam-officer')->update([
            'description' => 'Uploads departmental courses in their academic department for college submission.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'results.submit')->update([
            'label' => 'Submit results for approval',
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('key', 'results.faculty_approve')->update([
            'label' => 'Faculty approve or return results',
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('key', 'results.board')->update([
            'label' => 'Board clear or request corrections',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('slug', 'faculty-exam-officer')->update([
            'name' => 'Faculty Exam Officer',
            'description' => 'Uploads faculty-lane courses in their faculty and approves faculty submissions.',
            'updated_at' => now(),
        ]);
    }
};
