<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            if (! Schema::hasColumn('course_offerings', 'lecturer_name')) {
                $table->string('lecturer_name')->nullable()->after('faculty_staff_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            if (Schema::hasColumn('course_offerings', 'lecturer_name')) {
                $table->dropColumn('lecturer_name');
            }
        });
    }
};
