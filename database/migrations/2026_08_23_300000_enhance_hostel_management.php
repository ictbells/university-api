<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hostels', function (Blueprint $table) {
            $table->string('category')->default('undergraduate')->after('gender');
            $table->boolean('is_active')->default(true)->after('category');
        });

        Schema::create('hostel_level_windows', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->foreignId('academic_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['category', 'academic_level_id', 'academic_term_id'], 'hostel_level_windows_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_level_windows');

        Schema::table('hostels', function (Blueprint $table) {
            $table->dropColumn(['category', 'is_active']);
        });
    }
};
