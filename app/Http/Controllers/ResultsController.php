<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Grade;
use App\Models\GradeBoundary;
use App\Models\GradingScale;
use App\Models\Student;
use App\Services\GradeEntryService;
use App\Services\GradeWorkflowService;
use App\Support\GpaCalculator;
use App\Support\GradeAuditLogger;
use App\Support\GradeStatus;
use App\Support\ListSessionLevelFilter;
use App\Support\ResultOfficerScope;
use App\Support\SubmissionListReportBuilder;
use App\Support\TranscriptBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultsController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private GradeEntryService $entry,
        private GradeWorkflowService $workflow,
    ) {}

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);

        $countsQuery = Grade::query();
        ResultOfficerScope::constrainGrades($countsQuery, $request->user());
        $counts = $countsQuery
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'counts' => collect(GradeStatus::all())->mapWithKeys(
                fn ($status) => [$status => (int) ($counts[$status] ?? 0)]
            ),
            'released' => (int) ($counts[GradeStatus::RELEASED] ?? 0),
            'draft' => (int) ($counts[GradeStatus::DRAFT] ?? 0),
        ];
    }

    public function meta(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);

        return [
            'terms' => AcademicTerm::query()
                ->with('session:id,label')
                ->orderByDesc('is_current')
                ->orderByDesc('id')
                ->get(['id', 'academic_session_id', 'name', 'session_label', 'is_current']),
            'levels' => AcademicLevel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'name', 'code']),
            'faculties' => Faculty::query()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'faculty_id']),
        ];
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);

        $query = Grade::query()->withResolved()->latest('id');
        ResultOfficerScope::constrainGrades($query, $request->user());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('academic_term_id')) {
            $query->forTerm((int) $request->input('academic_term_id'));
        }
        $query->forSession(ListSessionLevelFilter::sessionId($request));
        $query->forLevel(ListSessionLevelFilter::levelCode($request));
        if ($request->filled('faculty_id')) {
            $query->forFaculty((int) $request->input('faculty_id'));
        }
        if ($request->filled('department_id')) {
            $query->forDepartment((int) $request->input('department_id'));
        }
        if ($request->filled('sitting') && in_array($request->input('sitting'), GradeStatus::sittings(), true)) {
            $query->where('sitting', $request->input('sitting'));
        }
        if ($request->filled('student_id')) {
            $query->forStudent((int) $request->input('student_id'));
        }
        if ($request->filled('course_id')) {
            $courseId = (int) $request->input('course_id');
            $query->whereHas('offering', fn ($q) => $q->where('course_id', $courseId));
        }
        if ($request->filled('course')) {
            $code = '%'.$request->input('course').'%';
            $query->whereHas('offering.course', fn ($q) => $q->where('code', 'like', $code)->orWhere('title', 'like', $code));
        }
        if ($request->filled('matric')) {
            $matric = '%'.$request->input('matric').'%';
            $query->where(function ($q) use ($matric) {
                $q->whereHas('student', fn ($s) => $s->where('matric_number', 'like', $matric))
                    ->orWhereHas('enrollment.student', fn ($s) => $s->where('matric_number', 'like', $matric));
            });
        }
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('student', function ($q) use ($search) {
                    $q->where('matric_number', 'like', $search)
                        ->orWhere('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search);
                })->orWhereHas('enrollment.student', function ($q) use ($search) {
                    $q->where('matric_number', 'like', $search)
                        ->orWhere('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search);
                });
            });
        }

        return $query->paginate(min(5000, max(10, (int) $request->input('per_page', 25))));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.write'), 403);

        $data = $request->validate([
            'enrollment_id' => 'nullable|integer|exists:enrollments,id|required_without:course_offering_id',
            'student_id' => 'nullable|integer|exists:students,id|required_with:course_offering_id',
            'course_offering_id' => 'nullable|integer|exists:course_offerings,id|required_without:enrollment_id',
            'sitting' => ['nullable', Rule::in(GradeStatus::sittings())],
            'ca_score' => 'nullable|numeric|min:0|max:100',
            'exam_score' => 'nullable|numeric|min:0|max:100',
            'score' => 'nullable|numeric|min:0|max:100',
            'letter' => 'nullable|string|max:4',
            'points' => 'nullable|numeric|min:0|max:5',
        ]);

        return $this->officeGate('results.store', null, $data, 'Enter result', fn () => $this->entry->upsert($data, $request->user()));
    }

    public function update(Request $request, Grade $grade)
    {
        abort_unless($request->user()->hasPermission('results.write'), 403);

        $data = $request->validate([
            'ca_score' => 'nullable|numeric|min:0|max:100',
            'exam_score' => 'nullable|numeric|min:0|max:100',
            'score' => 'nullable|numeric|min:0|max:100',
            'letter' => 'nullable|string|max:4',
            'points' => 'nullable|numeric|min:0|max:5',
            'sitting' => ['nullable', Rule::in(GradeStatus::sittings())],
        ]);
        ResultOfficerScope::assertCanMutate($request->user(), $grade);
        $data['student_id'] = $grade->student_id ?: $grade->enrollment?->student_id;
        $data['course_offering_id'] = $grade->course_offering_id ?: $grade->enrollment?->course_offering_id;
        $data['enrollment_id'] = $grade->enrollment_id;
        $data['sitting'] = $data['sitting'] ?? $grade->sitting;

        return $this->officeGate(
            'results.update',
            $grade,
            ['grade_id' => $grade->id, ...$data],
            'Update result',
            fn () => $this->entry->upsert($data, $request->user()),
        );
    }

    public function destroy(Request $request, Grade $grade)
    {
        abort_unless($request->user()->hasPermission('results.write'), 403);
        ResultOfficerScope::assertCanMutate($request->user(), $grade);

        return $this->officeGate('results.destroy', $grade, ['grade_id' => $grade->id], 'Delete result', function () use ($request, $grade) {
            $this->entry->destroy($grade, $request->user());

            return response()->json(['message' => 'Grade deleted.']);
        });
    }

    public function submit(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.submit'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:grades,id']);
        ResultOfficerScope::assertCanActOnGrades($request->user(), $data['ids']);

        return $this->officeGate('results.submit', null, $data, 'Submit results', fn () => $this->workflow->submit($data['ids'], $request->user()));
    }

    public function facultyApprove(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.faculty_approve'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:grades,id']);
        ResultOfficerScope::assertFacultyApprove($request->user(), $data['ids']);

        return $this->officeGate('results.faculty_approve', null, $data, 'Faculty approve results', fn () => $this->workflow->facultyApprove($data['ids'], $request->user()));
    }

    public function facultyReturn(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.faculty_approve'), 403);
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:grades,id',
            'note' => 'nullable|string|max:2000',
        ]);
        ResultOfficerScope::assertFacultyApprove($request->user(), $data['ids']);

        return $this->officeGate(
            'results.faculty_return',
            null,
            $data,
            'Faculty return results',
            fn () => $this->workflow->facultyReturn($data['ids'], $request->user(), $data['note'] ?? null),
        );
    }

    public function boardClear(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.board'), 403);
        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:grades,id',
            'academic_term_id' => 'required|integer|exists:academic_terms,id',
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'note' => 'nullable|string|max:2000',
        ]);

        return $this->officeGate(
            'results.board_clear',
            null,
            $data,
            'Board clear results',
            fn () => $this->workflow->boardClear(
                (int) $data['academic_term_id'],
                isset($data['faculty_id']) ? (int) $data['faculty_id'] : null,
                isset($data['department_id']) ? (int) $data['department_id'] : null,
                $request->user(),
                $data['note'] ?? null,
                $data['ids'] ?? null,
            ),
            'results-board',
        );
    }

    public function boardRequestCorrections(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.board'), 403);
        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:grades,id',
            'academic_term_id' => 'required|integer|exists:academic_terms,id',
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'note' => 'nullable|string|max:2000',
        ]);

        return $this->workflow->boardRequestCorrections(
            (int) $data['academic_term_id'],
            isset($data['faculty_id']) ? (int) $data['faculty_id'] : null,
            isset($data['department_id']) ? (int) $data['department_id'] : null,
            $request->user(),
            $data['note'] ?? null,
            $data['ids'] ?? null,
        );
    }

    public function release(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.release'), 403);
        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:grades,id',
            'academic_term_id' => 'nullable|integer|exists:academic_terms,id',
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'force' => 'nullable|boolean',
        ]);

        return $this->officeGate(
            'results.release',
            null,
            $data,
            'Release results to students',
            function () use ($data, $request) {
                $force = (bool) ($data['force'] ?? false);
                if (! empty($data['ids'])) {
                    return $this->workflow->release($data['ids'], $request->user(), $force);
                }
                abort_unless(! empty($data['academic_term_id']), 422, 'Provide ids or academic_term_id.');

                return $this->workflow->releaseScope(
                    (int) $data['academic_term_id'],
                    isset($data['faculty_id']) ? (int) $data['faculty_id'] : null,
                    isset($data['department_id']) ? (int) $data['department_id'] : null,
                    $request->user(),
                    $force,
                );
            },
            'results-release',
        );
    }

    public function importTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('results.import'), 403);

        return $this->entry->importTemplate();
    }

    public function import(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.import'), 403);
        $data = $request->validate([
            'course_offering_id' => 'required|integer|exists:course_offerings,id',
            'score_component' => 'nullable|in:ca,exam,total',
            'sitting' => ['nullable', Rule::in(GradeStatus::sittings())],
            'csv' => 'required_without:file|string',
            'file' => 'required_without:csv|file',
        ]);

        $csv = $data['csv'] ?? $this->entry->fileToCsv($request->file('file'));
        $payload = [
            'course_offering_id' => (int) $data['course_offering_id'],
            'score_component' => $data['score_component'] ?? 'total',
            'sitting' => $data['sitting'] ?? GradeStatus::SITTING_MAIN,
            'csv' => $csv,
        ];

        return $this->officeGate(
            'results.import',
            null,
            $payload,
            'Import results CSV',
            fn () => $this->entry->importCsv(
                (string) $payload['csv'],
                (int) $payload['course_offering_id'],
                $payload['score_component'],
                $request->user(),
                $payload['sitting'],
            ),
        );
    }

    public function students(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);
        $search = trim((string) $request->input('search', ''));
        $query = Student::query()->with('program:id,name,code')->orderBy('matric_number');
        if ($level = ListSessionLevelFilter::levelCode($request)) {
            $values = Grade::levelMatchValues($level);
            if ($values !== []) {
                $query->whereIn('current_level', $values);
            }
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('matric_number', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('student_number', 'like', $like);
            });
        }

        return $query->paginate(min(50, max(10, (int) $request->input('per_page', 20))));
    }

    public function studentGrades(Request $request, Student $student)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);

        $grades = Grade::query()
            ->withResolved()
            ->forStudent($student->id)
            ->orderByDesc('id')
            ->get();

        $termId = $request->filled('academic_term_id') ? (int) $request->input('academic_term_id') : null;
        $termGrades = $termId
            ? $grades->filter(fn (Grade $g) => (int) ($g->resolvedOffering()?->academic_term_id ?? 0) === $termId)
            : $grades;

        $gradeIds = $grades->pluck('id')->all();
        $audit = AuditLog::query()
            ->where('module', 'results')
            ->where('entity_type', Grade::class)
            ->whereIn('entity_id', $gradeIds ?: [0])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'student' => $student->only(['id', 'first_name', 'last_name', 'matric_number', 'current_level']),
            'grades' => $grades,
            'gpa' => GpaCalculator::compute($termGrades, false),
            'cgpa' => GpaCalculator::compute($grades, false),
            'transcript' => TranscriptBuilder::forStudent($student, false),
            'audit' => $audit,
        ];
    }

    public function staffTranscript(Request $request, Student $student)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);

        return TranscriptBuilder::forStudent($student, true);
    }

    public function gradingScales(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('results.read') || $request->user()->hasPermission('scales.manage'),
            403,
        );

        GradingScale::ensureDefault();

        return GradingScale::query()->with('boundaries')->orderByDesc('is_default')->orderBy('id')->get();
    }

    public function updateGradingScale(Request $request, GradingScale $gradingScale)
    {
        abort_unless($request->user()->hasPermission('scales.manage'), 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'max_points' => 'sometimes|numeric|min:1|max:10',
            'is_default' => 'sometimes|boolean',
            'boundaries' => 'sometimes|array|min:1',
            'boundaries.*.letter' => 'required_with:boundaries|string|max:4',
            'boundaries.*.min_score' => 'required_with:boundaries|numeric|min:0|max:100',
            'boundaries.*.max_score' => 'required_with:boundaries|numeric|min:0|max:100',
            'boundaries.*.grade_point' => 'required_with:boundaries|numeric|min:0|max:10',
        ]);

        return $this->officeGate(
            'results.update_grading_scale',
            $gradingScale,
            ['grading_scale_id' => $gradingScale->id, ...$data],
            'Update grading scale',
            function () use ($request, $gradingScale, $data) {
                if (! empty($data['is_default'])) {
                    GradingScale::query()->where('id', '!=', $gradingScale->id)->update(['is_default' => false]);
                }

                $gradingScale->fill(collect($data)->only(['name', 'max_points', 'is_default'])->all())->save();

                if (isset($data['boundaries'])) {
                    $gradingScale->boundaries()->delete();
                    foreach ($data['boundaries'] as $row) {
                        GradeBoundary::query()->create([
                            'grading_scale_id' => $gradingScale->id,
                            'letter' => strtoupper($row['letter']),
                            'min_score' => $row['min_score'],
                            'max_score' => $row['max_score'],
                            'grade_point' => $row['grade_point'],
                        ]);
                    }
                }

                GradeAuditLogger::gradingScaleUpdated($request->user(), ['grading_scale_id' => $gradingScale->id]);

                return $gradingScale->fresh('boundaries');
            },
        );
    }

    public function submissionList(Request $request, string $scope)
    {
        abort_unless(
            $request->user()->hasPermission('results.read')
                || $request->user()->hasPermission('results.submit')
                || $request->user()->hasPermission('results.faculty_approve')
                || $request->user()->hasPermission('results.board'),
            403
        );
        abort_unless(in_array($scope, ['department', 'faculty', 'board'], true), 404);

        $reportScope = str_contains($request->path(), 'board-lists') ? 'board' : $scope;

        $data = $request->validate([
            'academic_term_id' => 'required|integer|exists:academic_terms,id',
            'academic_session_id' => 'nullable|integer|exists:academic_sessions,id',
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'status' => 'nullable|string',
            'level' => 'nullable|string',
            'sitting' => ['nullable', Rule::in(GradeStatus::sittings())],
            'format' => 'nullable|in:json,html,pdf,doc,docx',
        ]);

        $format = $data['format'] ?? 'json';
        if (in_array($format, ['pdf', 'doc', 'docx'], true) && empty($data['level'])) {
            abort(422, 'Select a level to download the list.');
        }

        $term = AcademicTerm::query()->with('session')->findOrFail($data['academic_term_id']);
        $faculty = isset($data['faculty_id']) ? Faculty::query()->find($data['faculty_id']) : null;
        $department = isset($data['department_id']) ? Department::query()->with('faculty')->find($data['department_id']) : null;

        $query = Grade::query()
            ->withResolved()
            ->with(['department.faculty'])
            ->forTerm($term->id);
        ResultOfficerScope::constrainGrades($query, $request->user());

        if (! empty($data['status']) && $data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        if (! empty($data['sitting'])) {
            $query->where('sitting', $data['sitting']);
        }
        if ($department) {
            $query->forDepartment($department->id);
        } elseif ($faculty) {
            $query->forFaculty($faculty->id, $reportScope === 'board');
        }

        $report = SubmissionListReportBuilder::build(
            $query->get(),
            $term,
            $reportScope,
            $data['status'] ?? null,
            $faculty,
            $department,
            $data['level'] ?? null,
        );

        if ($format === 'json') {
            return $report;
        }

        $html = view('reports.submission-list', ['report' => $report])->render();
        $basename = $this->submissionListFilename($reportScope, $term, $report);

        if ($format === 'html') {
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        if (in_array($format, ['doc', 'docx'], true)) {
            return response($html, 200, [
                'Content-Type' => 'application/msword; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$basename.'.doc"',
            ]);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$basename.'.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function submissionListFilename(string $scope, AcademicTerm $term, array $report): string
    {
        $session = str_replace(['/', ' '], '-', (string) ($term->session?->label ?: $term->session_label ?: $term->id));
        $semester = str_contains(strtolower((string) $term->name), 'second') ? 'second' : 'first';
        $suffix = ! empty($report['is_supplementary']) ? '-supplementary' : '';

        $label = match ($scope) {
            'department' => 'department-results',
            'faculty' => 'faculty-results',
            default => 'senate-list',
        };

        return $label.'-'.$session.'-'.$semester.$suffix;
    }

    public function offerings(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('results.read')
                || $request->user()->hasPermission('results.write')
                || $request->user()->hasPermission('results.import'),
            403,
        );

        $query = CourseOffering::query()
            ->with(['course.department', 'term'])
            ->withCount(['enrollments as enrolled_count' => fn ($q) => $q->enrolled()]);
        ResultOfficerScope::constrainOfferings($query, $request->user());

        if ($request->filled('academic_term_id')) {
            $query->where('academic_term_id', (int) $request->input('academic_term_id'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->whereHas('course', function ($q) use ($search) {
                $q->where('code', 'like', $search)->orWhere('title', 'like', $search);
            });
        }

        return $query->orderByDesc('id')->limit(500)->get();
    }
}
