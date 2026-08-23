<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('gender');
            $table->boolean('is_reserved')->default(false)->after('is_active');
            $table->string('reserve_note')->nullable()->after('is_reserved');
        });
    }

    public function down(): void
    {
        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'is_reserved', 'reserve_note']);
        });
    }
};
