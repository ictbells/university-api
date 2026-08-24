<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('office_nav_links')
            ->where('nav_key', 'candidate-data')
            ->get();

        foreach ($rows as $row) {
            DB::table('office_nav_links')->updateOrInsert(
                [
                    'linkable_type' => $row->linkable_type,
                    'linkable_id' => $row->linkable_id,
                    'nav_key' => 'import-applicants',
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
        DB::table('office_nav_links')->where('nav_key', 'import-applicants')->delete();
    }
};
