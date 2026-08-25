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
            'format' => 'required|in:pdf,excel,word',
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

        $term = AcademicTerm::current();
        $students = RegistrationListQuery::fromRequest($request)
            ->limit(RegistrationExportService::MAX_ROWS)
            ->get()
            ->map(fn (Student $student) => $this->decorate($student, $term));

        $showEntryMode = array_key_exists('show_entry_mode', $data)
            ? (bool) $data['show_entry_mode']
            : true;

        return $this->exports->export(
            $data['format'],
            $students,
            $data['title'] ?? 'Registrations report',
            RegistrationListQuery::filterSummary($request),
            $showEntryMode,
        );
    }

    private function decorate(Student $student, ?AcademicTerm $term): Student
    {
        $roster = $this->registration->rosterStatusFor($student, $term);
        $student->setAttribute('tuition_percent', TuitionProgress::percentPaid($student));
        $student->setAttribute('course_reg_status', $roster['status']);
        $student->setAttribute('enrolled_units', $roster['enrolled_units']);
        $student->setAttribute('extension_status', $roster['extension_status']);
        $student->setAttribute('studentship_current', \App\Support\Studentship::isCurrent($student));

        return $student;
    }
}
