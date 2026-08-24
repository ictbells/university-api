<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->boolean('auto_schedule')->default(true)->after('is_current');
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn('auto_schedule');
        });
    }
};
