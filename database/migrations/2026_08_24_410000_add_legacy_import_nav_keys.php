<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->copyNavKey('finance', ['import-invoices', 'import-wallet']);
        $this->copyNavKey('import-applicants', ['import-students']);
        $this->copyNavKey('course-registration', ['import-students']);
    }

    public function down(): void
    {
        DB::table('office_nav_links')->whereIn('nav_key', [
            'import-invoices',
            'import-wallet',
            'import-students',
        ])->delete();
    }

    /**
     * @param  list<string>  $targets
     */
    private function copyNavKey(string $source, array $targets): void
    {
        $rows = DB::table('office_nav_links')->where('nav_key', $source)->get();
        foreach ($rows as $row) {
            foreach ($targets as $target) {
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
    }
};
