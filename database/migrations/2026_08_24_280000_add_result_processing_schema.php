<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('max_points', 4, 2)->default(5);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('grade_boundaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_scale_id')->constrained('grading_scales')->cascadeOnDelete();
            $table->string('letter', 4);
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->decimal('grade_point', 4, 2);
            $table->timestamps();
            $table->unique(['grading_scale_id', 'letter']);
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['enrollment_id']);
            $table->string('sitting')->default('main')->after('enrollment_id');
            $table->decimal('ca_score', 5, 2)->nullable()->after('score');
            $table->decimal('exam_score', 5, 2)->nullable()->after('ca_score');
            $table->string('status')->default('draft')->after('exam_score');
            $table->string('source')->nullable()->after('status');
            $table->string('source_ref')->nullable()->after('source');
            $table->string('upload_lane')->nullable()->after('source_ref');
            $table->foreignId('faculty_id')->nullable()->after('upload_lane')->constrained('faculties')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('faculty_id')->constrained('departments')->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('entered_by');
            $table->timestamp('faculty_approved_at')->nullable()->after('submitted_at');
            $table->timestamp('board_cleared_at')->nullable()->after('faculty_approved_at');
            $table->timestamp('released_at')->nullable()->after('board_cleared_at');
            $table->text('correction_note')->nullable()->after('released_at');
            $table->unique(['enrollment_id', 'sitting']);
            $table->index('status');
            $table->index(['faculty_id', 'department_id']);
        });

        Schema::create('grade_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->nullable()->constrained('grades')->nullOnDelete();
            $table->string('action');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->unsignedBigInteger('academic_term_id')->nullable();
            $table->string('sitting')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });

        Schema::create('grade_board_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type');
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamp('lists_generated_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('corrections_requested_at')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(
                ['scope_type', 'faculty_id', 'department_id', 'academic_term_id'],
                'grade_board_scopes_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_board_scopes');
        Schema::dropIfExists('grade_status_events');

        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['enrollment_id', 'sitting']);
            $table->dropConstrainedForeignId('faculty_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('entered_by');
            $table->dropColumn([
                'sitting',
                'ca_score',
                'exam_score',
                'status',
                'source',
                'source_ref',
                'upload_lane',
                'submitted_at',
                'faculty_approved_at',
                'board_cleared_at',
                'released_at',
                'correction_note',
            ]);
            $table->unique(['enrollment_id']);
        });

        Schema::dropIfExists('grade_boundaries');
        Schema::dropIfExists('grading_scales');
    }
};
