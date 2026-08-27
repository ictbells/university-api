<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'academic_session_id')) {
                $table->foreignId('academic_session_id')
                    ->nullable()
                    ->after('application_id')
                    ->constrained('academic_sessions')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'level_code')) {
                $table->string('level_code', 20)->nullable()->after('academic_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'academic_session_id')) {
                $table->dropConstrainedForeignId('academic_session_id');
            }
            if (Schema::hasColumn('invoices', 'level_code')) {
                $table->dropColumn('level_code');
            }
        });
    }
};
