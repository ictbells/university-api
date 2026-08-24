<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('ends_on');
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->boolean('auto_close_on_end')->default(false)->after('closed_by_user_id');
        });

        Schema::create('academic_session_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->unsignedInteger('promoted_count')->default(0);
            $table->unsignedInteger('skipped_final_count')->default(0);
            $table->unsignedInteger('skipped_inactive_count')->default(0);
            $table->unsignedInteger('skipped_no_program_count')->default(0);
            $table->timestamp('ran_at');
            $table->foreignId('ran_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('student_level_progressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->unsignedSmallInteger('from_level');
            $table->unsignedSmallInteger('to_level');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_level_progressions');
        Schema::dropIfExists('academic_session_closures');
        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn(['closed_at', 'auto_close_on_end']);
        });
    }
};
