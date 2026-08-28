<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Program;
use App\Models\Staff;
use App\Models\Student;
use App\Models\WorkflowTemplate;
use App\Services\AcademicCatalogImportService;
use App\Services\AuditWriter;
use App\Support\ListSessionLevelFilter;
use App\Support\ProgrammeEligibility;
use App\Support\StudyLevel;
use App\Support\WorkflowCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        if ($modes === ['jupeb']) {
            $programs = $programs->filter(
                fn (Program $program) => $program->isOfferedAtJupebCentre()
            )->values();
        }
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
            'study_level' => 'required|'.StudyLevel::rule(),
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
        $data = StudyLevel::applyToProgramPayload($data);
        $data['is_research_degree'] = (bool) ($data['is_research_degree'] ?? false);
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        if (empty($data['workflow_template_id'])) {
            $data['workflow_template_id'] = WorkflowCatalog::ensureDefaultId(new Program($data));
        }
        return $this->officeGate('academic.store_program', null, $data + ['course_ids' => $courseIds], 'Create programme', function () use ($data, $courseIds) {
            $program = Program::query()->create($data);
            if ($courseIds !== null) {
                $this->syncProgramCourseIds($program, $courseIds);
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
            'study_level' => 'sometimes|'.StudyLevel::rule(),
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
        if (array_key_exists('entry_modes', $data) || array_key_exists('study_level', $data)) {
            $data = StudyLevel::applyToProgramPayload($data, $program);
        }
        $nextWorkflowId = array_key_exists('workflow_template_id', $data)
            ? $data['workflow_template_id']
            : $program->workflow_template_id;
        if (empty($nextWorkflowId)) {
            $data['workflow_template_id'] = WorkflowCatalog::ensureDefaultId(new Program(array_merge(
                $program->only(['study_level', 'entry_modes', 'is_research_degree']),
                $data,
            )));
        }
        return $this->officeGate('academic.update_program', $program, ['program_id' => $program->id, ...$data], 'Update programme', function () use ($program, $data, $before) {
            if (array_key_exists('course_ids', $data)) {
                $this->syncProgramCourseIds($program, $data['course_ids'] ?? []);
                unset($data['course_ids']);
            }
            $program->update($data);
            $this->audit->record('program.updated', 'Programme updated', 'academic', 'program', $program->id, $before, $program);

            return $program->load(['department.faculty', 'courses', 'workflowTemplate.stages']);
        });
    }

    public function syncProgramCourses(Request $request, Program $program)
    {
        $data = $request->validate([
            'courses' => 'present|array',
            'courses.*.course_id' => 'required|integer|exists:courses,id|distinct',
            'courses.*.academic_level_id' => 'nullable|exists:academic_levels,id',
        ]);
        $this->assertCurriculumLevels($program, $data['courses']);

        $courseIds = collect($data['courses'])->pluck('course_id')->map(fn ($id) => (int) $id)->all();
        $types = Course::query()->whereIn('id', $courseIds)->pluck('course_type', 'id');
        $sync = [];
        foreach ($data['courses'] as $row) {
            $courseId = (int) $row['course_id'];
            $sync[$courseId] = [
                'academic_level_id' => $row['academic_level_id'] ?? null,
                'bucket' => ($types[$courseId] ?? null) ?: 'departmental',
            ];
        }

        $before = $program->courses()->pluck('courses.id')->all();

        return $this->officeGate(
            'academic.sync_program_courses',
            $program,
            ['program_id' => $program->id, 'courses' => $data['courses']],
            'Assign programme courses',
            function () use ($program, $sync, $before) {
                $program->courses()->sync($sync);
                $this->audit->record(
                    'program.courses_synced',
                    'Programme courses assigned',
                    'academic',
                    'program',
                    $program->id,
                    ['course_ids' => $before],
                    ['course_ids' => array_keys($sync)],
                );

                return $program->load(['department.faculty', 'courses' => fn ($query) => $query->orderBy('code')])
                    ->loadCount('students');
            },
        );
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

    public function courses(Request $request)
    {
        $query = Course::query()
            ->with(['department', 'programs'])
            ->orderByRaw("CASE COALESCE(course_type, 'departmental') WHEN 'general' THEN 1 WHEN 'faculty' THEN 2 ELSE 3 END")
            ->orderBy('code');
        ListSessionLevelFilter::applyLevelToCoursePrograms($query, $request);

        return $query->get();
    }

    public function storeCourse(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'code' => 'required|string',
            'title' => 'required|string',
            'units' => 'required|integer|min:1',
            'course_type' => ['required', Rule::in(Course::TYPES)],
            'status' => ['nullable', Rule::in(Course::STATUSES)],
            'program_ids' => 'nullable|array',
            'program_ids.*' => 'integer|exists:programs,id',
        ]);
        $data['status'] = $data['status'] ?? 'core';
        $programIds = $this->normalizeIds($data['program_ids'] ?? []);
        unset($data['program_ids']);
        return $this->officeGate('academic.store_course', null, $data + ['program_ids' => $programIds], 'Create course', function () use ($data, $programIds) {
            $course = Course::query()->create($data);
            $this->syncCoursePrograms($course, $programIds);
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
            'course_type' => ['sometimes', Rule::in(Course::TYPES)],
            'status' => ['nullable', Rule::in(Course::STATUSES)],
            'program_ids' => 'sometimes|nullable|array',
            'program_ids.*' => 'integer|exists:programs,id',
        ]);
        $hasProgramIds = $request->exists('program_ids');
        $programIds = $hasProgramIds ? $this->normalizeIds($data['program_ids'] ?? []) : null;
        unset($data['program_ids']);
        $payload = ['course_id' => $course->id, ...$data];
        if ($hasProgramIds) {
            $payload['program_ids'] = $programIds;
        }
        return $this->officeGate('academic.update_course', $course, $payload, 'Update course', function () use ($course, $data, $before, $programIds) {
            $course->update($data);
            $fresh = $course->fresh() ?? $course;
            if ($programIds !== null) {
                $this->syncCoursePrograms($fresh, $programIds);
            } elseif (array_key_exists('course_type', $data)) {
                $this->syncCoursePrograms(
                    $fresh,
                    $fresh->programs()->pluck('programs.id')->map(fn ($id) => (int) $id)->all(),
                );
            }
            $this->audit->record('course.updated', 'Course updated', 'academic', 'course', $course->id, $before, $course);

            return $fresh->load(['department', 'programs']);
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
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Map a course onto programmes. Keeps existing level assignments for programmes that remain.
     *
     * @param  list<int>  $programIds
     */
    private function syncCoursePrograms(Course $course, array $programIds): void
    {
        $type = $course->course_type ?: 'departmental';
        $existing = $course->programs()->get()->keyBy(fn (Program $program) => (int) $program->id);
        $sync = [];
        foreach ($programIds as $id) {
            $id = (int) $id;
            $sync[$id] = [
                'bucket' => $type,
                'academic_level_id' => $existing->get($id)?->pivot?->academic_level_id,
            ];
        }
        $course->programs()->sync($sync);
    }

    /**
     * @param  list<array{course_id: int, academic_level_id?: int|null}>  $rows
     */
    private function assertCurriculumLevels(Program $program, array $rows): void
    {
        $levelIds = collect($rows)
            ->pluck('academic_level_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($levelIds->isEmpty()) {
            return;
        }

        $expected = StudyLevel::ofProgram($program);
        $mismatch = AcademicLevel::query()
            ->whereIn('id', $levelIds)
            ->where('study_level', '!=', $expected)
            ->exists();
        if ($mismatch) {
            $label = $expected === StudyLevel::JUPEB ? 'JUPEB' : $expected;
            throw ValidationException::withMessages([
                'courses' => "Assign only {$label} levels to this programme. JUPEB and undergraduate curricula are separate.",
            ]);
        }
    }

    /**
     * Map courses onto a programme. Keeps existing level assignments for courses that remain.
     *
     * @param  list<int|string>  $courseIds
     */
    private function syncProgramCourseIds(Program $program, array $courseIds): void
    {
        $courseIds = $this->normalizeIds($courseIds);
        $existing = $program->courses()->get()->keyBy(fn (Course $course) => (int) $course->id);
        $types = Course::query()->whereIn('id', $courseIds)->pluck('course_type', 'id');
        $sync = [];
        foreach ($courseIds as $id) {
            $sync[$id] = [
                'bucket' => ($types[$id] ?? null) ?: 'departmental',
                'academic_level_id' => $existing->get($id)?->pivot?->academic_level_id,
            ];
        }
        $program->courses()->sync($sync);
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

        $query = $student->enrollments()->with(['offering.course', 'grades', 'grade']);
        if ($request->boolean('current')) {
            $termId = AcademicTerm::query()->where('is_current', true)->value('id');
            if (! $termId) {
                return [];
            }
            $query->enrolled()->whereHas('offering', fn ($offerings) => $offerings->where('academic_term_id', $termId));
        }

        $rows = $query->get();

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
                ? [
                    'letter' => $released->resolvedLetter() ?: null,
                    'points' => $released->resolvedGradePoints(),
                    'score' => $released->score !== null && $released->score !== '' ? (float) $released->score : null,
                    'status' => $released->status,
                ]
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

        $payload = \App\Support\TranscriptBuilder::forStudent($target, true, true);
        $payload['official'] = false;
        $payload['can_sign'] = false;
        $payload['notice'] = 'Unofficial transcript for viewing only. It is not signed and is not valid for official use.';

        if ($request->input('format') === 'html') {
            $target->loadMissing('program');

            return response()->view('reports.unofficial-transcript', [
                'report' => [
                    'university' => (string) \App\Models\Setting::getValue('university_name', 'Bells University of Technology'),
                    'generated_at' => now()->format('d M Y H:i'),
                    'cgpa' => $payload['cgpa'] ?? $payload['gpa'] ?? null,
                    'total_credits' => $payload['total_credits'] ?? null,
                    'terms' => $payload['terms'] ?? [],
                    'student' => [
                        'name' => trim(($target->first_name ?? '').' '.($target->last_name ?? '')),
                        'matric_number' => $target->matric_number,
                        'programme' => $target->program?->name,
                    ],
                ],
            ]);
        }

        return $payload;
    }

    public function unsignedTranscript(Request $request)
    {
        abort_if($request->user()->isStaffPortalUser(), 403, 'Transcripts are not available in the staff portal.');

        $target = $request->user()->student;
        abort_unless($target, 404);

        $sessionId = $request->filled('academic_session_id') ? (int) $request->input('academic_session_id') : null;
        $termId = $request->filled('academic_term_id') ? (int) $request->input('academic_term_id') : null;
        $payload = \App\Support\TranscriptBuilder::unsignedForStudent($target, $sessionId, $termId);

        if ($request->input('format') === 'html') {
            $target->loadMissing('program');

            return response()->view('reports.unsigned-transcript', [
                'report' => [
                    'university' => (string) \App\Models\Setting::getValue('university_name', 'Bells University of Technology'),
                    'generated_at' => now()->format('d M Y H:i'),
                    'scope_label' => $payload['scope_label'] ?? null,
                    'gpa' => $payload['gpa'] ?? null,
                    'total_credits' => $payload['total_credits'] ?? null,
                    'units_registered' => $payload['units_registered'] ?? null,
                    'terms' => $payload['terms'] ?? [],
                    'notice' => $payload['notice'] ?? null,
                    'student' => [
                        'name' => trim(($target->first_name ?? '').' '.($target->last_name ?? '')),
                        'matric_number' => $target->matric_number,
                        'programme' => $target->program?->name,
                    ],
                ],
            ]);
        }

        return $payload;
    }

    public function workflowTemplates()
    {
        WorkflowCatalog::seed();

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
