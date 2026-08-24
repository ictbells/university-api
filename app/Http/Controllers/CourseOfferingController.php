<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Staff;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class CourseOfferingController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function index(Request $request)
    {
        $query = CourseOffering::query()
            ->with(['course.department', 'term', 'lecturer.user'])
            ->withCount(['enrollments as enrolled_count' => fn ($q) => $q->enrolled()]);

        if ($request->filled('academic_term_id')) {
            $query->where('academic_term_id', (int) $request->academic_term_id);
        }
        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }

        return $query->orderByDesc('id')->get()->map(function (CourseOffering $offering) {
            $offering->setAttribute('seats_left', max(0, (int) $offering->capacity - (int) $offering->enrolled_count));

            return $offering;
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'academic_term_id' => 'required|exists:academic_terms,id',
            'faculty_staff_id' => 'nullable|exists:staff,id',
            'section' => 'nullable|string|max:20',
            'capacity' => 'required|integer|min:1|max:1000',
        ]);
        $data['section'] = $data['section'] ?? 'A';

        $exists = CourseOffering::query()
            ->where('course_id', $data['course_id'])
            ->where('academic_term_id', $data['academic_term_id'])
            ->where('section', $data['section'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'This section is already offered for the selected course and semester.'], 422);
        }

        $offering = CourseOffering::query()->create($data);
        $this->audit->record('offering.created', 'Course offering created', 'academic', 'course_offering', $offering->id, null, $offering);

        return $offering->load(['course.department', 'term', 'lecturer.user']);
    }

    public function update(Request $request, CourseOffering $offering)
    {
        $before = $offering->toArray();
        $data = $request->validate([
            'course_id' => 'sometimes|exists:courses,id',
            'academic_term_id' => 'sometimes|exists:academic_terms,id',
            'faculty_staff_id' => 'nullable|exists:staff,id',
            'section' => 'nullable|string|max:20',
            'capacity' => 'sometimes|integer|min:1|max:1000',
        ]);
        $offering->update($data);
        $this->audit->record('offering.updated', 'Course offering updated', 'academic', 'course_offering', $offering->id, $before, $offering);

        return $offering->fresh(['course.department', 'term', 'lecturer.user']);
    }

    public function destroy(CourseOffering $offering)
    {
        if ($offering->enrollments()->enrolled()->exists()) {
            return response()->json(['message' => 'Remove enrolled students before deleting this offering.'], 422);
        }
        $before = $offering->toArray();
        $offering->delete();
        $this->audit->record('offering.deleted', 'Course offering deleted', 'academic', 'course_offering', $offering->id, $before, null);

        return response()->noContent();
    }

    public function lecturers()
    {
        return Staff::query()
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get(['id', 'user_id', 'staff_number', 'title']);
    }

    public function courses()
    {
        return Course::query()->with('department')->orderBy('code')->get(['id', 'code', 'title', 'units', 'course_type', 'department_id']);
    }

    public function terms()
    {
        return AcademicTerm::query()
            ->with('session')
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->get();
    }
}
