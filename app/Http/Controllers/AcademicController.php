<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Program;
use App\Models\Staff;
use App\Models\Student;
use App\Models\WorkflowTemplate;
use App\Services\AcademicCatalogImportService;
use App\Services\AuditWriter;
use App\Support\ProgrammeEligibility;
use App\Support\WorkflowCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AcademicController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    use Concerns\ImportsAcademicCatalog;

    public function __construct(
        private AuditWriter $audit,
        private AcademicCatalogImportService $catalogImporter,
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
            'is_active' => 'boolean',
        ]);
        $courseIds = $data['course_ids'] ?? null;
        unset($data['course_ids']);
        $data['is_research_degree'] = (bool) ($data['is_research_degree'] ?? false);
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        if (empty($data['workflow_template_id'])) {
            $data['workflow_template_id'] = WorkflowCatalog::idByCode(
                WorkflowCatalog::defaultCodeFor(new Program($data))
            );
        }
        return $this->officeGate('academic.store_program', null, $data + ['course_ids' => $courseIds], 'Create programme', function () use ($data, $courseIds) {
            $program = Program::query()->create($data);
            if ($courseIds !== null) {
                $program->courses()->sync($courseIds);
            }
            $this->audit->record('program.created', 'Programme created', 'academic', 'program', $program->id, null, $program);

            return $program->load(['department.faculty', 'courses', 'workflowTemplate.stages']);
        });
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
        return $this->officeGate('academic.update_program', $program, ['program_id' => $program->id, ...$data], 'Update programme', function () use ($program, $data, $before) {
            if (array_key_exists('course_ids', $data)) {
                $program->courses()->sync($data['course_ids'] ?? []);
                unset($data['course_ids']);
            }
            $program->update($data);
            $this->audit->record('program.updated', 'Programme updated', 'academic', 'program', $program->id, $before, $program);

            return $program->load(['department.faculty', 'courses', 'workflowTemplate.stages']);
        });
    }

    public function destroyProgram(Program $program)
    {
        $before = $program->toArray();

        return $this->officeGate('academic.destroy_program', $program, ['program_id' => $program->id], 'Delete programme', function () use ($program, $before) {
            $program->delete();
            $this->audit->record('program.deleted', 'Programme deleted', 'academic', 'program', $program->id, $before, null);

            return response()->noContent();
        });
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
        return $this->officeGate('academic.store_course', null, $data + ['program_ids' => $programIds], 'Create course', function () use ($data, $programIds) {
            $course = Course::query()->create($data);
            $course->programs()->sync($this->programSync($programIds, $data['course_type']));
            $this->audit->record('course.created', 'Course created', 'academic', 'course', $course->id, null, $course);

            return $course->load(['department', 'programs']);
        });
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
        return $this->officeGate('academic.update_course', $course, ['course_id' => $course->id, ...$data], 'Update course', function () use ($course, $data, $before) {
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
        });
    }

    public function destroyCourse(Course $course)
    {
        $before = $course->toArray();

        return $this->officeGate('academic.destroy_course', $course, ['course_id' => $course->id], 'Delete course', function () use ($course, $before) {
            $course->delete();
            $this->audit->record('course.deleted', 'Course deleted', 'academic', 'course', $course->id, $before, null);

            return response()->noContent();
        });
    }

    public function importTemplate(): StreamedResponse
    {
        return $this->catalogImportTemplate('courses');
    }

    public function importCourses(Request $request)
    {
        return $this->runCatalogImport($request, 'courses', 'course.imported', 'Course catalogue imported');
    }

    public function importProgramsTemplate(): StreamedResponse
    {
        return $this->catalogImportTemplate('programmes');
    }

    public function importPrograms(Request $request)
    {
        return $this->runCatalogImport($request, 'programmes', 'program.imported', 'Programmes imported');
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

        $rows = $student->enrollments()->with(['offering.course', 'grades', 'grade'])->get();

        return $rows->map(function ($enrollment) {
            $released = $enrollment->grades
                ->filter(fn ($g) => \App\Support\GradeStatus::isReleased((string) $g->status))
                ->sortByDesc(fn ($g) => $g->sitting === 'supplementary' ? 1 : 0)
                ->first();
            $pending = $enrollment->grades->contains(
                fn ($g) => ! \App\Support\GradeStatus::isReleased((string) $g->status)
            );
            $payload = $enrollment->toArray();
            $payload['grade'] = $released
                ? $released->only(['letter', 'points', 'score', 'status'])
                : null;
            $payload['pending_grade'] = $pending && ! $released;

            return $payload;
        });
    }

    public function transcript(Request $request, ?Student $student = null)
    {
        abort_if($request->user()->isStaffPortalUser(), 403, 'Transcripts are not available in the staff portal.');

        $target = $request->user()->student;
        abort_unless($target, 404);
        abort_if($student && $student->id !== $target->id, 403);

        return \App\Support\TranscriptBuilder::forStudent($target, true, true);
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
