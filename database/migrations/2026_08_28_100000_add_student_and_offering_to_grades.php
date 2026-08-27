<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            if (! Schema::hasColumn('grades', 'student_id')) {
                $table->foreignId('student_id')->nullable()->after('enrollment_id')->constrained('students')->nullOnDelete();
            }
            if (! Schema::hasColumn('grades', 'course_offering_id')) {
                $table->foreignId('course_offering_id')->nullable()->after('student_id')->constrained('course_offerings')->nullOnDelete();
            }
        });

        $grades = DB::table('grades')->whereNotNull('enrollment_id')->orderBy('id')->get();
        foreach ($grades as $row) {
            $enrollment = DB::table('enrollments')->where('id', $row->enrollment_id)->first();
            if (! $enrollment) {
                continue;
            }
            DB::table('grades')->where('id', $row->id)->update([
                'student_id' => $enrollment->student_id,
                'course_offering_id' => $enrollment->course_offering_id,
            ]);
        }

        try {
            Schema::table('grades', function (Blueprint $table) {
                $table->dropUnique(['enrollment_id', 'sitting']);
            });
        } catch (Throwable) {
            // Index name differs per driver, or already dropped.
        }

        Schema::table('grades', function (Blueprint $table) {
            $table->unsignedBigInteger('enrollment_id')->nullable()->change();
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->unique(['student_id', 'course_offering_id', 'sitting']);
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            try {
                $table->dropUnique(['student_id', 'course_offering_id', 'sitting']);
            } catch (Throwable) {
            }
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->unsignedBigInteger('enrollment_id')->nullable(false)->change();
            $table->unique(['enrollment_id', 'sitting']);
        });

        Schema::table('grades', function (Blueprint $table) {
            if (Schema::hasColumn('grades', 'course_offering_id')) {
                $table->dropConstrainedForeignId('course_offering_id');
            }
            if (Schema::hasColumn('grades', 'student_id')) {
                $table->dropConstrainedForeignId('student_id');
            }
        });
    }
};
