<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('application_number')->nullable()->unique()->after('id');
            $table->string('jamb_registration')->nullable()->after('entry_mode');
        });

        $year = now()->format('Y');
        $prefix = "APP/{$year}/";
        $sequence = 0;
        foreach (DB::table('applications')->orderBy('id')->get(['id']) as $row) {
            $sequence++;
            DB::table('applications')->where('id', $row->id)->update([
                'application_number' => $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['application_number', 'jamb_registration']);
        });
    }
};
