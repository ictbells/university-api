<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candidate_data') || Schema::hasColumn('candidate_data', 'deleted_at')) {
            return;
        }

        Schema::table('candidate_data', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('candidate_data') || ! Schema::hasColumn('candidate_data', 'deleted_at')) {
            return;
        }

        Schema::table('candidate_data', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
