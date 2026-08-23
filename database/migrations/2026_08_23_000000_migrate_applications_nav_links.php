<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REPLACEMENTS = [
        'applications' => [
            'admissions-undergraduate',
            'admissions-jupeb',
            'admissions-postgraduate',
        ],
    ];

    public function up(): void
    {
        foreach (self::REPLACEMENTS as $oldKey => $newKeys) {
            $rows = DB::table('office_nav_links')->where('nav_key', $oldKey)->get();
            foreach ($rows as $row) {
                foreach ($newKeys as $newKey) {
                    DB::table('office_nav_links')->updateOrInsert(
                        [
                            'linkable_type' => $row->linkable_type,
                            'linkable_id' => $row->linkable_id,
                            'nav_key' => $newKey,
                        ],
                        [
                            'created_at' => $row->created_at,
                            'updated_at' => now(),
                        ],
                    );
                }
                DB::table('office_nav_links')->where('id', $row->id)->delete();
            }
        }
    }

    public function down(): void
    {
        $groups = DB::table('office_nav_links')
            ->whereIn('nav_key', array_merge(...array_values(self::REPLACEMENTS)))
            ->get()
            ->groupBy(fn ($row) => $row->linkable_type.'|'.$row->linkable_id);

        foreach ($groups as $rows) {
            $first = $rows->first();
            DB::table('office_nav_links')->insert([
                'linkable_type' => $first->linkable_type,
                'linkable_id' => $first->linkable_id,
                'nav_key' => 'applications',
                'created_at' => $first->created_at,
                'updated_at' => now(),
            ]);
            DB::table('office_nav_links')
                ->whereIn('id', $rows->pluck('id'))
                ->delete();
        }
    }
};
