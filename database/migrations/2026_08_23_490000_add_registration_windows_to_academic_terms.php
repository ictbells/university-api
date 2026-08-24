<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->timestamp('normal_registration_closes_at')->nullable()->after('ends_on');
            $table->timestamp('late_registration_closes_at')->nullable()->after('normal_registration_closes_at');
        });
    }

    public function down(): void
    {
        Schema::table('academic_terms', function (Blueprint $table) {
            $table->dropColumn(['normal_registration_closes_at', 'late_registration_closes_at']);
        });
    }
};
