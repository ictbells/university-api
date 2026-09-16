<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_academic_level', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_level_id')->constrained()->cascadeOnDelete();
            $table->unique(['hostel_id', 'academic_level_id'], 'hostel_academic_level_unique');
        });

        Schema::create('hostel_room_academic_level', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_level_id')->constrained()->cascadeOnDelete();
            $table->unique(['hostel_room_id', 'academic_level_id'], 'hostel_room_academic_level_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_room_academic_level');
        Schema::dropIfExists('hostel_academic_level');
    }
};
