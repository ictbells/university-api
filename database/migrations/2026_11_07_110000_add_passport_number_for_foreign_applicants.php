<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('passport_number', 40)->nullable()->after('billing_currency');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('passport_number', 40)->nullable()->after('nin');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('passport_number');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('passport_number');
        });
    }
};
