<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Services\CourseRegistrationService;
use App\Services\RegistrationExportService;
use App\Support\RegistrationListQuery;
use App\Support\TuitionProgress;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function __construct(
        private RegistrationExportService $exports,
        private CourseRegistrationService $registration,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('registrations.view'), 403);

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $paginator = RegistrationListQuery::fromRequest($request)->paginate($perPage);
        $term = AcademicTerm::current();
        $paginator->getCollection()->transform(fn (Student $student) => $this->decorate($student, $term));
        $payload = $paginator->toArray();
        $payload['summary'] = RegistrationListQuery::summary($request);

        return response()->json($payload);
    }

    public function sessions(Request $request)
    {
        abort_unless($request->user()->hasPermission('registrations.view'), 403);

        return AcademicSession::query()
            ->with('semesters')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AcademicSession $session) => [
                'id' => $session->id,
                'session_label' => $session->label,
                'name' => $session->label,
                'is_current' => $session->semesters->contains(fn ($s) => $s->is_current),
            ]);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->hasPermission('registrations.view'), 403);

        $data = $request->validate([
            'format' => 'required|in:pdf,excel,word,csv',
            'title' => 'nullable|string|max:120',
            'entry_mode' => 'nullable|string',
            'entry_modes' => 'nullable',
            'academic_term_id' => 'nullable|integer|exists:academic_terms,id',
            'academic_session_id' => 'nullable|integer|exists:academic_sessions,id',
            'session' => 'nullable|string',
            'program_id' => 'nullable|integer|exists:programs,id',
            'level' => 'nullable|string',
            'search' => 'nullable|string',
            'show_entry_mode' => 'nullable|boolean',
            'course_reg_status' => 'nullable|in:not_started,in_progress,registered',
            'studentship' => 'nullable|in:current,alumni,all',
        ]);

        @ini_set('memory_limit', '2048M');
        if (function_exists('set_time_limit')) {
            @set_time_limit($data['format'] === 'csv' ? 600 : 300);
        }

        $format = $data['format'];
        $maxRows = RegistrationExportService::maxRowsFor($format);
        $query = RegistrationListQuery::fromRequest($request);
        $total = (clone $query)->count();
        if ($total > $maxRows) {
            $label = match ($format) {
                'excel' => 'Excel',
                'csv' => 'CSV',
                default => strtoupper($format),
            };

            return response()->json([
                'message' => "Too many registrations for {$label} ({$total}). "
                    .($format === 'csv'
                        ? "Narrow the filters so the list is at most {$maxRows}."
                        : ($format === 'excel'
                            ? "Use CSV for larger lists, or narrow the filters to at most {$maxRows}."
                            : "Use Excel or CSV, or narrow the filters to at most {$maxRows} registrations.")),
                'total' => $total,
                'max_rows' => $maxRows,
                'format' => $format,
            ], 422);
        }

        $term = AcademicTerm::current();
        $showEntryMode = array_key_exists('show_entry_mode', $data)
            ? (bool) $data['show_entry_mode']
            : true;
        $title = $data['title'] ?? 'Registrations report';
        $filters = RegistrationListQuery::filterSummary($request);

        try {
            if ($format === 'csv') {
                return $this->exports->streamCsv($title, $filters, $showEntryMode, function (callable $emit) use ($query, $maxRows, $term) {
                    $written = 0;
                    (clone $query)->reorder()
                        ->orderBy('students.id')
                        ->chunkById(500, function ($chunk) use ($emit, &$written, $maxRows, $term) {
                            foreach ($chunk as $student) {
                                if ($written >= $maxRows) {
                                    return false;
                                }
                                $written++;
                                $emit($this->exports->mapRow($this->decorate($student, $term)));
                            }

                            return $written < $maxRows;
                        }, 'students.id', 'id');
                });
            }

            $students = $query->limit($maxRows)->get()
                ->map(fn (Student $student) => $this->decorate($student, $term));

            return $this->exports->export(
                $format,
                $students,
                $title,
                $filters,
                $showEntryMode,
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not build the '.match ($format) {
                    'excel' => 'Excel',
                    'csv' => 'CSV',
                    'word' => 'Word',
                    default => strtoupper($format),
                }.' file. Try CSV, or narrow the filters.',
            ], 422);
        }
    }

    private function decorate(Student $student, ?AcademicTerm $term): Student
    {
        $roster = $this->registration->rosterStatusFor($student, $term);
        $student->setAttribute('tuition_percent', TuitionProgress::currentSessionPercent($student));
        $student->setAttribute('course_reg_status', $roster['status']);
        $student->setAttribute('enrolled_units', $roster['enrolled_units']);
        $student->setAttribute('extension_status', $roster['extension_status']);
        $student->setAttribute('studentship_current', \App\Support\Studentship::isCurrent($student));

        return $student;
    }
}
