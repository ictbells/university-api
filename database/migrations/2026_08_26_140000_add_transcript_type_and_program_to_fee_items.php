<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_items', 'transcript_type')) {
                $table->string('transcript_type', 32)->nullable()->after('entry_mode');
            }
            if (! Schema::hasColumn('fee_items', 'program_id')) {
                $table->foreignId('program_id')->nullable()->after('transcript_type')->constrained('programs')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            if (Schema::hasColumn('fee_items', 'program_id')) {
                $table->dropConstrainedForeignId('program_id');
            }
            if (Schema::hasColumn('fee_items', 'transcript_type')) {
                $table->dropColumn('transcript_type');
            }
        });
    }
};
