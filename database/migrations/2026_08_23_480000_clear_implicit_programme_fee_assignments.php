<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fee catalog items are not programme assignments. Remove rows created by
        // legacy program.tuition_amount backfill or demo seeding so staff must assign
        // schedules explicitly under Fees & payments → Programme fees.
        DB::table('programme_fees')->delete();
        DB::table('programs')->whereNull('deleted_at')->update(['tuition_amount' => null]);
    }

    public function down(): void
    {
        // Non-reversible: assignments must be recreated manually.
    }
};
