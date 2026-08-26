<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medical_profiles') || Schema::hasColumn('medical_profiles', 'nhis_coverage_amount')) {
            return;
        }

        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->decimal('nhis_coverage_amount', 12, 2)->nullable()->after('nhis_coverage_percent');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('medical_profiles') || ! Schema::hasColumn('medical_profiles', 'nhis_coverage_amount')) {
            return;
        }

        Schema::table('medical_profiles', function (Blueprint $table) {
            $table->dropColumn('nhis_coverage_amount');
        });
    }
};
