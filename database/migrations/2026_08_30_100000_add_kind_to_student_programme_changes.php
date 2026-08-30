<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_programme_changes', function (Blueprint $table) {
            $table->string('kind', 40)->default('change_of_programme')->after('same_college');
            $table->index(['student_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('student_programme_changes', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
