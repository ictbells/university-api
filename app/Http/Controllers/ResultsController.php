<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Grade;
use App\Models\GradeBoundary;
use App\Models\GradingScale;
use App\Models\Student;
use App\Services\GradeEntryService;
use App\Services\GradeWorkflowService;
use App\Support\GradeAuditLogger;
use App\Support\GradeStatus;
use App\Support\ListSessionLevelFilter;
use App\Support\SubmissionListReportBuilder;
use App\Support\TranscriptBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $counts = Grade::query()
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

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);

        $query = Grade::query()
            ->with([
                'enrollment.student:id,first_name,last_name,matric_number',
                'enrollment.offering.course:id,code,title,units',
                'enrollment.offering.term:id,name,session_label',
            ])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('academic_term_id')) {
            $termId = (int) $request->input('academic_term_id');
            $query->whereHas('enrollment.offering', fn ($q) => $q->where('academic_term_id', $termId));
        }
        ListSessionLevelFilter::applySessionToTermRelation($query, $request, 'enrollment.offering.term');
        ListSessionLevelFilter::applyToStudentRelation($query, $request, 'enrollment.student');
        if ($request->filled('faculty_id')) {
            $query->where('faculty_id', (int) $request->input('faculty_id'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        }
        if ($request->filled('student_id')) {
            $studentId = (int) $request->input('student_id');
            $query->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->whereHas('enrollment.student', function ($q) use ($search) {
                $q->where('matric_number', 'like', $search)
                    ->orWhere('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search);
            });
        }

        return $query->paginate(min(100, max(10, (int) $request->input('per_page', 25))));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.write'), 403);

        $data = $request->validate([
            'enrollment_id' => 'required|integer|exists:enrollments,id',
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

        return $this->officeGate('results.destroy', $grade, ['grade_id' => $grade->id], 'Delete result', function () use ($request, $grade) {
            $this->entry->destroy($grade, $request->user());

            return response()->json(['message' => 'Grade deleted.']);
        });
    }

    public function submit(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.submit'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:grades,id']);

        return $this->officeGate('results.submit', null, $data, 'Submit results', fn () => $this->workflow->submit($data['ids'], $request->user()));
    }

    public function facultyApprove(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.faculty_approve'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:grades,id']);

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
            ),
            'results-board',
        );
    }

    public function boardRequestCorrections(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.board'), 403);
        $data = $request->validate([
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

    public function import(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.import'), 403);
        $data = $request->validate([
            'course_offering_id' => 'required|integer|exists:course_offerings,id',
            'score_component' => 'nullable|in:ca,exam,total',
            'csv' => 'required_without:file|string',
            'file' => 'required_without:csv|file',
        ]);

        $csv = $data['csv'] ?? file_get_contents($request->file('file')->getRealPath());
        $payload = [
            'course_offering_id' => (int) $data['course_offering_id'],
            'score_component' => $data['score_component'] ?? 'total',
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
            ),
        );
    }

    public function students(Request $request)
    {
        abort_unless($request->user()->hasPermission('results.read'), 403);
        $search = trim((string) $request->input('search', ''));
        $query = Student::query()->with('program:id,name,code')->orderBy('matric_number');
        ListSessionLevelFilter::applyToStudents($query, $request);
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
            ->with(['enrollment.offering.course', 'enrollment.offering.term'])
            ->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))
            ->orderByDesc('id')
            ->get();

        return [
            'student' => $student->only(['id', 'first_name', 'last_name', 'matric_number', 'current_level']),
            'grades' => $grades,
            'transcript' => TranscriptBuilder::forStudent($student, false),
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
        abort_unless($request->user()->hasPermission('results.read'), 403);
        abort_unless(in_array($scope, ['department', 'faculty', 'board'], true), 404);

        $data = $request->validate([
            'academic_term_id' => 'required|integer|exists:academic_terms,id',
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'status' => 'nullable|string',
            'level' => 'nullable|string',
            'format' => 'nullable|in:json,html,pdf',
        ]);

        $term = AcademicTerm::query()->with('session')->findOrFail($data['academic_term_id']);
        $faculty = isset($data['faculty_id']) ? Faculty::query()->find($data['faculty_id']) : null;
        $department = isset($data['department_id']) ? Department::query()->with('faculty')->find($data['department_id']) : null;

        $query = Grade::query()
            ->with([
                'enrollment.student',
                'enrollment.offering.course',
                'department.faculty',
            ])
            ->whereHas('enrollment.offering', fn ($q) => $q->where('academic_term_id', $term->id));

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($department) {
            $query->where('department_id', $department->id);
        } elseif ($faculty) {
            $query->where('faculty_id', $faculty->id);
        }

        $report = SubmissionListReportBuilder::build(
            $query->get(),
            $term,
            $scope,
            $data['status'] ?? null,
            $faculty,
            $department,
            $data['level'] ?? null,
        );

        $format = $data['format'] ?? 'json';
        if ($format === 'json') {
            return $report;
        }

        $html = view('reports.submission-list', ['report' => $report])->render();
        if ($format === 'html') {
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="submission-list-'.$scope.'.pdf"',
        ]);
    }
}
