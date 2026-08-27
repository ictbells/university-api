<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\Staff;
use App\Services\AuditWriter;
use App\Services\CourseRegistrationService;
use App\Support\ListSessionLevelFilter;
use App\Support\ResultOfficerScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseOfferingController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private AuditWriter $audit,
        private CourseRegistrationService $registration,
    ) {}

    public function index(Request $request)
    {
        $query = CourseOffering::query()
            ->with(['course.department', 'course.programs:id,name,code', 'term', 'lecturer.user'])
            ->withCount(['enrollments as enrolled_count' => fn ($q) => $q->enrolled()]);

        if ($request->boolean('upload_lane_only')) {
            ResultOfficerScope::constrainOfferings($query, $request->user());
        }

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
        $courseIds = $this->selectedCourseIds($request);
        $request->merge([
            'course_ids' => $courseIds,
            'course_id' => $courseIds[0] ?? null,
        ]);
        $data = $request->validate([
            'course_id' => 'nullable|integer|exists:courses,id',
            'course_ids' => 'required|array|min:1|max:50',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
            'academic_term_id' => 'required|exists:academic_terms,id',
            'faculty_staff_id' => 'nullable|exists:staff,id',
            'lecturer_name' => 'nullable|string|max:190',
            'section' => 'nullable|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:1000',
        ]);
        unset($data['course_id'], $data['course_ids']);
        $data['section'] = $data['section'] ?? 'A';
        $data['lecturer_name'] = $this->normalizedLecturerName($data['lecturer_name'] ?? null);
        $data['capacity'] = $this->normalizedCapacity($data['capacity'] ?? null);

        $existing = CourseOffering::query()
            ->with('course:id,code')
            ->where('academic_term_id', $data['academic_term_id'])
            ->where('section', $data['section'])
            ->whereIn('course_id', $courseIds)
            ->get();
        if ($existing->isNotEmpty()) {
            $codes = $existing->map(fn (CourseOffering $row) => $row->course?->code ?: '#'.$row->course_id)->implode(', ');

            return response()->json([
                'message' => 'Already offered this semester for section '.$data['section'].': '.$codes.'.',
            ], 422);
        }

        $count = count($courseIds);
        $summary = $count === 1 ? 'Create course offering' : 'Create '.$count.' course offerings';

        return $this->officeGate(
            'academic.store_offering',
            null,
            [...$data, 'course_ids' => $courseIds],
            $summary,
            function () use ($data, $courseIds) {
                $offerings = DB::transaction(function () use ($data, $courseIds) {
                    $created = [];
                    foreach ($courseIds as $courseId) {
                        $created[] = CourseOffering::query()->create([
                            ...$data,
                            'course_id' => $courseId,
                        ])->load(['course.department', 'term', 'lecturer.user']);
                    }

                    return $created;
                });

                foreach ($offerings as $offering) {
                    $this->audit->record('offering.created', 'Course offering created', 'academic', 'course_offering', $offering->id, null, $offering);
                }

                return count($offerings) === 1
                    ? $offerings[0]
                    : ['created' => count($offerings), 'offerings' => $offerings];
            },
        );
    }

    public function publishFromCurriculum(Request $request)
    {
        $data = $request->validate([
            'academic_term_id' => 'required|exists:academic_terms,id',
            'program_id' => 'nullable|exists:programs,id',
        ]);

        $term = AcademicTerm::query()->findOrFail($data['academic_term_id']);
        $program = ! empty($data['program_id'])
            ? Program::query()->findOrFail($data['program_id'])
            : null;
        $termLabel = trim(($term->session_label ?: '').' '.$term->name);
        $summary = $program
            ? 'Publish '.$program->name.' courses as offerings for '.$termLabel
            : 'Publish programme courses as offerings for '.$termLabel;

        return $this->officeGate(
            'academic.publish_curriculum_offerings',
            $program,
            $data,
            $summary,
            function () use ($term, $program, $termLabel) {
                $result = $this->registration->publishCurriculumOfferings($term, $program);
                $this->audit->record(
                    'offering.curriculum_published',
                    $result['created'].' offering'.($result['created'] === 1 ? '' : 's').' published for '.$termLabel,
                    'academic',
                    'course_offering',
                    $program?->id,
                    null,
                    $result,
                );

                return $result;
            },
        );
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

    private function selectedCourseIds(Request $request): array
    {
        $raw = array_merge(
            is_array($request->input('course_ids')) ? $request->input('course_ids') : [],
            $request->exists('course_id') ? (array) $request->input('course_id') : [],
        );
        $ids = [];
        array_walk_recursive($raw, function ($value) use (&$ids) {
            $ids[] = (int) $value;
        });

        return array_values(array_unique(array_filter($ids)));
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
