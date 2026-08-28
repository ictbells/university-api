<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANNELS = [
        'admissions-undergraduate' => 'admissions-clearance-undergraduate',
        'admissions-jupeb' => 'admissions-clearance-jupeb',
        'admissions-postgraduate' => 'admissions-clearance-postgraduate',
    ];

    public function up(): void
    {
        foreach (self::CHANNELS as $source => $target) {
            $this->copyNavKey($source, $target);
        }

        DB::table('office_nav_links')->where('nav_key', 'admissions-clearance')->delete();
    }

    public function down(): void
    {
        $targets = array_values(self::CHANNELS);
        $rows = DB::table('office_nav_links')->whereIn('nav_key', $targets)->get();
        foreach ($rows as $row) {
            DB::table('office_nav_links')->updateOrInsert(
                [
                    'linkable_type' => $row->linkable_type,
                    'linkable_id' => $row->linkable_id,
                    'nav_key' => 'admissions-clearance',
                ],
                [
                    'created_at' => $row->created_at,
                    'updated_at' => now(),
                ],
            );
        }
        DB::table('office_nav_links')->whereIn('nav_key', $targets)->delete();
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
