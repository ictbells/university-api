<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcript_requests', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('student_id')->constrained('programs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transcript_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
    }
};
