<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->string('bedding_type', 20)->default('single')->after('capacity');
        });

        Schema::table('hostel_beds', function (Blueprint $table) {
            $table->string('bunk_position', 20)->nullable()->after('label');
            $table->unsignedTinyInteger('bunk_pair')->nullable()->after('bunk_position');
        });
    }

    public function down(): void
    {
        Schema::table('hostel_beds', function (Blueprint $table) {
            $table->dropColumn(['bunk_position', 'bunk_pair']);
        });

        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->dropColumn('bedding_type');
        });
    }
};
