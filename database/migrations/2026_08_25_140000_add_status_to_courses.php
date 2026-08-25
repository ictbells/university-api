<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('courses', 'status')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->string('status')->default('core')->after('course_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('courses', 'status')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
