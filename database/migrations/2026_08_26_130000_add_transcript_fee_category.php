<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('fee_categories')->where('code', 'transcript')->exists()) {
            $order = (int) (DB::table('fee_categories')->max('display_order') ?? 0) + 1;
            DB::table('fee_categories')->insert([
                'code' => 'transcript',
                'name' => 'Official transcript',
                'description' => 'Fee for official signed transcript requests from the public student portal. Paid online via Paystack before Registry processing.',
                'is_schedule' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fee_categories')->where('code', 'transcript')->delete();
    }
};
