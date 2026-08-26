<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('fee_categories')->where('code', 'clinic')->exists()) {
            $order = (int) (DB::table('fee_categories')->max('display_order') ?? 0) + 1;
            DB::table('fee_categories')->insert([
                'code' => 'clinic',
                'name' => 'Clinic services',
                'description' => 'Visit charges attached by clinic staff from this catalog. Distinct from the Medical levy programme schedule.',
                'is_schedule' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('fee_categories')
            ->where('code', 'medical')
            ->where('name', 'Medical / clinic')
            ->update([
                'name' => 'Medical levy',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        DB::table('fee_categories')->where('code', 'clinic')->delete();
        DB::table('fee_categories')
            ->where('code', 'medical')
            ->where('name', 'Medical levy')
            ->update([
                'name' => 'Medical / clinic',
                'updated_at' => now(),
            ]);
    }
};
