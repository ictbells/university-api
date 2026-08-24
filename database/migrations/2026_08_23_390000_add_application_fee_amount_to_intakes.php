<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->decimal('application_fee_amount', 12, 2)->nullable()->after('is_open');
        });

        $fees = DB::table('fee_items')
            ->where('category', 'application_fee')
            ->where('is_active', true)
            ->pluck('amount', 'entry_mode');

        foreach (DB::table('intakes')->whereNull('deleted_at')->get(['id', 'entry_mode']) as $intake) {
            $amount = $fees[$intake->entry_mode] ?? null;
            if ($amount !== null) {
                DB::table('intakes')->where('id', $intake->id)->update([
                    'application_fee_amount' => $amount,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropColumn('application_fee_amount');
        });
    }
};
