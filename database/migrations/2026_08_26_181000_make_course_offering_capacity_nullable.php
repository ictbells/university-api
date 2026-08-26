<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->default(50)->nullable(false)->change();
        });
    }
};
