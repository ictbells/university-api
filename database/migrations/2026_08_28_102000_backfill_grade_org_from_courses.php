<?php

use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Grade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Grade::query()
            ->where(function ($q) {
                $q->whereNull('department_id')
                    ->orWhereNull('faculty_id')
                    ->orWhereNull('student_id')
                    ->orWhereNull('course_offering_id');
            })
            ->orderBy('id')
            ->each(function (Grade $grade) {
                $offering = $grade->course_offering_id
                    ? CourseOffering::query()->with('course.department')->find($grade->course_offering_id)
                    : null;
                if (! $offering && $grade->enrollment_id) {
                    $enrollment = Enrollment::query()->with('offering.course.department')->find($grade->enrollment_id);
                    $offering = $enrollment?->offering;
                    if ($enrollment && ! $grade->student_id) {
                        $grade->student_id = $enrollment->student_id;
                    }
                    if ($enrollment && ! $grade->course_offering_id) {
                        $grade->course_offering_id = $enrollment->course_offering_id;
                    }
                }
                $course = $offering?->course;
                if ($course && ! $grade->department_id) {
                    $grade->department_id = $course->department_id;
                }
                if ($course && ! $grade->faculty_id) {
                    $grade->faculty_id = $course->department?->faculty_id;
                }
                if ($grade->isDirty()) {
                    $grade->save();
                }
            });
    }

    public function down(): void
    {
        //
    }
};
