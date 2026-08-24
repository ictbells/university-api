<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Program;
use App\Models\Staff;
use App\Models\Student;
use App\Models\WorkflowTemplate;
use App\Services\AuditWriter;
use App\Services\CourseCatalogImportService;
use App\Support\ProgrammeEligibility;
use App\Support\WorkflowCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicController extends Controller
{
    public function __construct(
        private AuditWriter $audit,
        private CourseCatalogImportService $importer,
    ) {}

    public function programs(Request $request)
    {
        $query = Program::query()->with(['department.faculty', 'workflowTemplate.stages'])->where('is_active', true);

        $modes = [];
        if ($request->filled('entry_modes')) {
            $modes = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
        } elseif ($request->filled('entry_mode')) {
            $modes = [(string) $request->entry_mode];
        }

        if ($modes !== []) {
            $query->where(function ($builder) use ($modes) {
                foreach ($modes as $index => $mode) {
                    $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                    $builder->{$method}('entry_modes', $mode);
                }
            });
        }

        $programs = $query->orderBy('name')->get();
        $application = $request->user()?->latestApplication;
        if ($application && $application->entry_mode === 'pg') {
            $application->loadMissing(['steps', 'documents', 'refereeInvites']);
            $programs->each(function (Program $program) use ($application) {
                $program->setAttribute('eligibility', ProgrammeEligibility::evaluate($program, $application));
            });
        }

        return $programs;
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
            'tuition_amount' => 'nullable|numeric|min:0',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
            'is_research_degree' => 'boolean',
            'eligibility' => 'nullable|array',
            'workflow_template_id' => 'nullable|exists:workflow_templates,id',
        ]);
        $courseIds = $data['course_ids'] ?? null;
        unset($data['course_ids']);
        $data['is_research_degree'] = (bool) ($data['is_research_degree'] ?? false);
        if (empty($data['workflow_template_id'])) {
            $data['workflow_template_id'] = WorkflowCatalog::idByCode(
                WorkflowCatalog::defaultCodeFor(new Program($data))
            );
        }
        $program = Program::query()->create($data);
        if ($courseIds !== null) {
            $program->courses()->sync($courseIds);
        }
        $this->audit->record('program.created', 'Programme created', 'academic', 'program', $program->id, null, $program);

        return $program->load(['department.faculty', 'courses', 'workflowTemplate.stages']);
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
            'tuition_amount' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
            'is_research_degree' => 'boolean',
            'eligibility' => 'nullable|array',
            'workflow_template_id' => 'nullable|exists:workflow_templates,id',
        ]);
        if (array_key_exists('course_ids', $data)) {
            $program->courses()->sync($data['course_ids'] ?? []);
            unset($data['course_ids']);
        }
        $program->update($data);
        $this->audit->record('program.updated', 'Programme updated', 'academic', 'program', $program->id, $before, $program);

        return $program->load(['department.faculty', 'courses', 'workflowTemplate.stages']);
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
            'course_type' => ['nullable', Rule::in(Course::TYPES)],
            'program_ids' => 'required_unless:course_type,general|array|min:1',
            'program_ids.*' => 'exists:programs,id',
        ]);
        $data['course_type'] = $data['course_type'] ?? 'departmental';
        $programIds = $data['program_ids'] ?? [];
        unset($data['program_ids']);
        if ($programIds === [] && $data['course_type'] === 'general') {
            $programIds = Program::query()->where('is_active', true)->pluck('id')->all();
        }
        $course = Course::query()->create($data);
        $course->programs()->sync($this->programSync($programIds, $data['course_type']));
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
            'course_type' => ['nullable', Rule::in(Course::TYPES)],
            'program_ids' => 'sometimes|array',
            'program_ids.*' => 'exists:programs,id',
        ]);
        $type = $data['course_type'] ?? $course->course_type ?? 'departmental';
        if (array_key_exists('program_ids', $data)) {
            $programIds = $data['program_ids'] ?? [];
            if ($programIds === [] && $type === 'general') {
                $programIds = Program::query()->where('is_active', true)->pluck('id')->all();
            }
            $course->programs()->sync($this->programSync($programIds, $type));
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

    public function importTemplate()
    {
        return $this->importer->template();
    }

    public function importCourses(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);
        try {
            $result = $this->importer->import($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->record('course.imported', 'Course catalogue imported', 'academic', 'course', null, null, $result);

        return $result;
    }

    /**
     * @param  list<int>  $programIds
     * @return array<int, array{bucket: string}>
     */
    private function programSync(array $programIds, string $type): array
    {
        $sync = [];
        foreach ($programIds as $id) {
            $sync[(int) $id] = ['bucket' => $type];
        }

        return $sync;
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

    public function workflowTemplates()
    {
        return WorkflowTemplate::query()->with('stages')->orderBy('name')->get();
    }

    public function supervisors(Program $program)
    {
        $program->loadMissing('department');
        abort_unless($program->department_id, 422, 'This programme has no department.');

        return Staff::query()
            ->where('department_id', $program->department_id)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get(['id', 'user_id', 'department_id', 'staff_number', 'title']);
    }
}
