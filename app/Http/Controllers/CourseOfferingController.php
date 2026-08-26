<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Staff;
use App\Services\AuditWriter;
use App\Support\ListSessionLevelFilter;
use Illuminate\Http\Request;

class CourseOfferingController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

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
        ListSessionLevelFilter::applySessionToTermRelation($query, $request);
        ListSessionLevelFilter::applyLevelToCoursePrograms($query, $request, 'course');

        return $query->orderByDesc('id')->get()->map(function (CourseOffering $offering) {
            $taken = (int) $offering->enrolled_count;
            $offering->setAttribute('seats_left', $offering->seatsLeft($taken));
            $offering->setAttribute('unlimited', $offering->hasUnlimitedCapacity());

            return $offering;
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'academic_term_id' => 'required|exists:academic_terms,id',
            'faculty_staff_id' => 'nullable|exists:staff,id',
            'lecturer_name' => 'nullable|string|max:190',
            'section' => 'nullable|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:1000',
        ]);
        $data['section'] = $data['section'] ?? 'A';
        $data['lecturer_name'] = $this->normalizedLecturerName($data['lecturer_name'] ?? null);
        $data['capacity'] = $this->normalizedCapacity($data['capacity'] ?? null);

        $exists = CourseOffering::query()
            ->where('course_id', $data['course_id'])
            ->where('academic_term_id', $data['academic_term_id'])
            ->where('section', $data['section'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'This section is already offered for the selected course and semester.'], 422);
        }

        return $this->officeGate('academic.store_offering', null, $data, 'Create course offering', function () use ($data) {
            $offering = CourseOffering::query()->create($data);
            $this->audit->record('offering.created', 'Course offering created', 'academic', 'course_offering', $offering->id, null, $offering);

            return $offering->load(['course.department', 'term', 'lecturer.user']);
        });
    }

    public function update(Request $request, CourseOffering $offering)
    {
        $before = $offering->toArray();
        $data = $request->validate([
            'course_id' => 'sometimes|exists:courses,id',
            'academic_term_id' => 'sometimes|exists:academic_terms,id',
            'faculty_staff_id' => 'nullable|exists:staff,id',
            'lecturer_name' => 'nullable|string|max:190',
            'section' => 'nullable|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:1000',
        ]);
        if (array_key_exists('lecturer_name', $data)) {
            $data['lecturer_name'] = $this->normalizedLecturerName($data['lecturer_name']);
        }
        if (array_key_exists('capacity', $data)) {
            $data['capacity'] = $this->normalizedCapacity($data['capacity']);
        }
        return $this->officeGate('academic.update_offering', $offering, ['offering_id' => $offering->id, ...$data], 'Update course offering', function () use ($offering, $data, $before) {
            $offering->update($data);
            $this->audit->record('offering.updated', 'Course offering updated', 'academic', 'course_offering', $offering->id, $before, $offering);

            return $offering->fresh(['course.department', 'term', 'lecturer.user']);
        });
    }

    public function destroy(CourseOffering $offering)
    {
        if ($offering->enrollments()->enrolled()->exists()) {
            return response()->json(['message' => 'Remove enrolled students before deleting this offering.'], 422);
        }
        $before = $offering->toArray();
        return $this->officeGate('academic.destroy_offering', $offering, ['offering_id' => $offering->id], 'Delete course offering', function () use ($offering, $before) {
            $offering->delete();
            $this->audit->record('offering.deleted', 'Course offering deleted', 'academic', 'course_offering', $offering->id, $before, null);

            return response()->noContent();
        });
    }

    public function lecturers()
    {
        return Staff::query()
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get(['id', 'user_id', 'staff_number', 'title']);
    }

    private function normalizedLecturerName(mixed $value): ?string
    {
        $name = trim((string) $value);

        return $name === '' ? null : $name;
    }

    private function normalizedCapacity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $capacity = (int) $value;

        return $capacity < 1 ? null : $capacity;
    }

    public function courses()
    {
        return Course::query()->with('department')->orderBy('code')->get(['id', 'code', 'title', 'units', 'course_type', 'status', 'department_id']);
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
