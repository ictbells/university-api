<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Program;
use App\Models\Student;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class AcademicController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function programs(Request $request)
    {
        $query = Program::query()->with('department.faculty')->where('is_active', true);
        if ($request->filled('entry_mode')) {
            $query->whereJsonContains('entry_modes', $request->entry_mode);
        }

        return $query->orderBy('name')->get();
    }

    public function storeProgram(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string',
            'code' => 'nullable|string',
            'award_type' => 'required|string',
            'study_level' => 'required|in:undergraduate,postgraduate',
            'entry_modes' => 'required|array|min:1',
            'entry_modes.*' => 'in:utme,de,jupeb,transfer,pg',
            'duration_years' => 'required|integer|min:1|max:10',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
        ]);
        $courseIds = $data['course_ids'] ?? null;
        unset($data['course_ids']);
        $program = Program::query()->create($data);
        if ($courseIds !== null) {
            $program->courses()->sync($courseIds);
        }
        $this->audit->record('program.created', 'Programme created', 'academic', 'program', $program->id, null, $program);

        return $program->load(['department.faculty', 'courses']);
    }

    public function updateProgram(Request $request, Program $program)
    {
        $before = $program->toArray();
        $data = $request->validate([
            'department_id' => 'sometimes|exists:departments,id',
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'award_type' => 'sometimes|string',
            'study_level' => 'sometimes|in:undergraduate,postgraduate',
            'entry_modes' => 'sometimes|array|min:1',
            'entry_modes.*' => 'in:utme,de,jupeb,transfer,pg',
            'duration_years' => 'sometimes|integer|min:1|max:10',
            'is_active' => 'boolean',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
        ]);
        if (array_key_exists('course_ids', $data)) {
            $program->courses()->sync($data['course_ids'] ?? []);
            unset($data['course_ids']);
        }
        $program->update($data);
        $this->audit->record('program.updated', 'Programme updated', 'academic', 'program', $program->id, $before, $program);

        return $program->load(['department.faculty', 'courses']);
    }

    public function destroyProgram(Program $program)
    {
        $before = $program->toArray();
        $program->delete();
        $this->audit->record('program.deleted', 'Programme deleted', 'academic', 'program', $program->id, $before, null);

        return response()->noContent();
    }

    public function courses()
    {
        return Course::query()->with(['department', 'programs'])->orderBy('code')->get();
    }

    public function storeCourse(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'code' => 'required|string',
            'title' => 'required|string',
            'units' => 'required|integer|min:1',
            'program_ids' => 'required|array|min:1',
            'program_ids.*' => 'exists:programs,id',
        ]);
        $programIds = $data['program_ids'];
        unset($data['program_ids']);
        $course = Course::query()->create($data);
        $course->programs()->sync($programIds);
        $this->audit->record('course.created', 'Course created', 'academic', 'course', $course->id, null, $course);

        return $course->load(['department', 'programs']);
    }

    public function updateCourse(Request $request, Course $course)
    {
        $before = $course->toArray();
        $data = $request->validate([
            'department_id' => 'sometimes|exists:departments,id',
            'code' => 'sometimes|string',
            'title' => 'sometimes|string',
            'units' => 'sometimes|integer|min:1',
            'program_ids' => 'sometimes|array|min:1',
            'program_ids.*' => 'exists:programs,id',
        ]);
        if (array_key_exists('program_ids', $data)) {
            $course->programs()->sync($data['program_ids']);
            unset($data['program_ids']);
        }
        $course->update($data);
        $this->audit->record('course.updated', 'Course updated', 'academic', 'course', $course->id, $before, $course);

        return $course->load(['department', 'programs']);
    }

    public function destroyCourse(Course $course)
    {
        $before = $course->toArray();
        $course->delete();
        $this->audit->record('course.deleted', 'Course deleted', 'academic', 'course', $course->id, $before, null);

        return response()->noContent();
    }

    public function myEnrollments(Request $request)
    {
        abort_if(
            $request->user()->isStaffPortalUser(),
            403,
            'Staff accounts do not use student enrollment views.',
        );

        $student = $request->user()->student;
        abort_unless($student, 404);

        return $student->enrollments()->with(['offering.course', 'grade'])->get();
    }

    public function transcript(Request $request, ?Student $student = null)
    {
        abort_if($request->user()->isStaffPortalUser(), 403, 'Transcripts are not available in the staff portal.');

        $target = $request->user()->student;
        abort_unless($target, 404);
        abort_if($student && $student->id !== $target->id, 403);

        $rows = $target->enrollments()->with(['offering.course', 'grade'])->get();
        $points = 0;
        $units = 0;
        foreach ($rows as $row) {
            if ($row->grade) {
                $points += $row->grade->points * $row->offering->course->units;
                $units += $row->offering->course->units;
            }
        }

        return [
            'student' => $target->only(['id', 'student_number', 'matric_number', 'first_name', 'last_name']),
            'gpa' => $units ? round($points / $units, 2) : 0,
            'rows' => $rows,
        ];
    }
}
