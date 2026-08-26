<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            ['key' => 'transcripts.view', 'module' => 'transcripts', 'label' => 'View transcript requests'],
            ['key' => 'transcripts.process', 'module' => 'transcripts', 'label' => 'Process transcript requests'],
        ] as $perm) {
            if (DB::table('permissions')->where('key', $perm['key'])->exists()) {
                continue;
            }
            DB::table('permissions')->insert([
                ...$perm,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', [
            'transcripts.view',
            'transcripts.process',
        ])->delete();
    }
};
