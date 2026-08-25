<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('fee_categories')->where('code', 'application_fee')->exists()) {
            $order = (int) (DB::table('fee_categories')->max('display_order') ?? 0) + 1;
            $now = now();
            DB::table('fee_categories')->insert([
                'code' => 'application_fee',
                'name' => 'Application fee',
                'description' => 'Paid online per entry mode before the applicant form.',
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
        DB::table('fee_categories')->where('code', 'application_fee')->delete();
    }
};
