<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->json('entry_modes')->nullable()->after('study_level');
        });

        $programs = DB::table('programs')->get();
        foreach ($programs as $program) {
            $modes = $program->study_level === 'postgraduate'
                ? json_encode(['pg'])
                : json_encode(['utme', 'de', 'jupeb', 'transfer']);
            DB::table('programs')->where('id', $program->id)->update(['entry_modes' => $modes]);
        }
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('entry_modes');
        });
    }
};
