<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_KEY = 'programme-courses';

    private const SOURCE_KEYS = ['programmes', 'courses'];

    public function up(): void
    {
        $sources = DB::table('office_nav_links')->whereIn('nav_key', self::SOURCE_KEYS)->get();
        $seen = [];
        foreach ($sources as $row) {
            $group = $row->linkable_type.'|'.$row->linkable_id;
            if (isset($seen[$group])) {
                continue;
            }
            $seen[$group] = true;
            $exists = DB::table('office_nav_links')->where([
                'linkable_type' => $row->linkable_type,
                'linkable_id' => $row->linkable_id,
                'nav_key' => self::NEW_KEY,
            ])->exists();
            if ($exists) {
                continue;
            }
            $payload = [
                'linkable_type' => $row->linkable_type,
                'linkable_id' => $row->linkable_id,
                'nav_key' => self::NEW_KEY,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('office_nav_links', 'require_create')) {
                $payload['require_create'] = $row->require_create ?? true;
                $payload['require_update'] = $row->require_update ?? true;
                $payload['require_delete'] = $row->require_delete ?? true;
                $payload['approval_chain'] = $row->approval_chain ?? 'both';
            }
            DB::table('office_nav_links')->insert($payload);
        }
    }

    public function down(): void
    {
        DB::table('office_nav_links')->where('nav_key', self::NEW_KEY)->delete();
    }
};
