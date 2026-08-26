<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fee_items') || ! Schema::hasColumn('fee_items', 'installment_tranche')) {
            return;
        }

        DB::table('fee_items')
            ->where('category', '!=', 'tuition')
            ->whereNotNull('installment_tranche')
            ->update(['installment_tranche' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Non-tuition installment shares were not a supported configuration.
    }
};
