<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->string('room_type', 40)->default('standard')->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->dropColumn('room_type');
        });
    }
};
