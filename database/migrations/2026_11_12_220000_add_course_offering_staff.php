<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_offering_staff')) {
            Schema::create('course_offering_staff', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_offering_id')->constrained('course_offerings')->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['course_offering_id', 'staff_id'], 'course_offering_staff_unique');
                $table->index('staff_id');
            });
        }

        if (Schema::hasColumn('course_offerings', 'faculty_staff_id')) {
            $rows = DB::table('course_offerings')
                ->whereNotNull('faculty_staff_id')
                ->get(['id', 'faculty_staff_id', 'created_at', 'updated_at']);
            $now = now();
            foreach ($rows as $row) {
                $exists = DB::table('course_offering_staff')
                    ->where('course_offering_id', $row->id)
                    ->where('staff_id', $row->faculty_staff_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('course_offering_staff')->insert([
                    'course_offering_id' => $row->id,
                    'staff_id' => $row->faculty_staff_id,
                    'created_at' => $row->created_at ?: $now,
                    'updated_at' => $row->updated_at ?: $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_offering_staff');
    }
};
