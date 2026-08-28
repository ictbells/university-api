<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->boolean('is_jupeb_centre')->default(false)->after('code');
        });

        $this->copyNavKey('admissions-jupeb', ['jupeb-matric']);
        $this->copyNavKey('import-students', ['jupeb-matric']);
    }

    public function down(): void
    {
        DB::table('office_nav_links')->where('nav_key', 'jupeb-matric')->delete();

        Schema::table('faculties', function (Blueprint $table) {
            $table->dropColumn('is_jupeb_centre');
        });
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
