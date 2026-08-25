<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\OlevelSubject;
use App\Models\Program;
use App\Services\AcademicCatalogImportService;
use App\Services\AuditWriter;
use App\Support\AdmissionEntryRules;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AcademicSetupController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    use Concerns\ImportsAcademicCatalog;

    public function __construct(
        private AuditWriter $audit,
        private AcademicCatalogImportService $catalogImporter,
    ) {}

    public function index()
    {
        return [
            'campuses' => Campus::query()->with('faculties.departments')->orderBy('name')->get(),
            'terms' => AcademicTerm::query()->with('session')->orderByDesc('is_current')->orderByDesc('id')->get(),
            'sessions' => AcademicSession::query()->with('semesters')->orderByDesc('id')->get(),
            'programs' => Program::query()->with(['department.faculty', 'workflowTemplate'])->orderBy('name')->get(),
            'courses' => Course::query()->with('department')->orderBy('code')->get(),
            'levels' => AcademicLevel::query()->orderBy('study_level')->orderBy('sort_order')->get(),
            'olevel_subjects' => OlevelSubject::query()->orderBy('name')->get(),
            'intakes' => Intake::query()->with('term')->orderByDesc('id')->get(),
        ];
    }

    public function campuses()
    {
        return Campus::query()->orderBy('name')->get();
    }

    public function faculties()
    {
        return Faculty::query()->with('campus')->orderBy('name')->get();
    }

    public function applicantColleges()
    {
        return Faculty::query()
            ->with(['campus', 'departments' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Faculty $faculty) => [
                'id' => $faculty->id,
                'name' => $faculty->name,
                'code' => $faculty->code,
                'campus' => $faculty->campus
                    ? ['id' => $faculty->campus->id, 'name' => $faculty->campus->name]
                    : null,
                'departments' => $faculty->departments
                    ->map(fn (Department $department) => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'code' => $department->code,
                    ])
                    ->values(),
            ])
            ->values();
    }

    public function departments()
    {
        return Department::query()->with('faculty.campus')->orderBy('name')->get();
    }

    public function terms()
    {
        return AcademicTerm::query()->with('session')->orderByDesc('is_current')->orderByDesc('id')->get();
    }

    public function sessions()
    {
        return AcademicSession::query()
            ->with(['semesters', 'latestClosure'])
            ->orderByDesc('id')
            ->get()
            ->map(function (AcademicSession $session) {
                $session->setAttribute('is_current', $session->semesters->contains(fn ($s) => $s->is_current));
                $session->setAttribute('is_closed', $session->isClosed());
                $closure = $session->latestClosure;
                if ($closure) {
                    $session->setAttribute('last_closure', [
                        'promoted_count' => $closure->promoted_count,
                        'skipped_final_count' => $closure->skipped_final_count,
                        'skipped_inactive_count' => $closure->skipped_inactive_count,
                        'skipped_no_program_count' => $closure->skipped_no_program_count,
                        'trigger' => $closure->trigger,
                        'ran_at' => $closure->ran_at,
                    ]);
                }

                return $session;
            });
    }

    public function programs()
    {
        return Program::query()->with(['department.faculty', 'courses', 'workflowTemplate.stages'])->orderBy('name')->get();
    }

    public function courses()
    {
        return Course::query()->with(['department.faculty', 'programs'])->orderBy('code')->get();
    }

    public function levelsList()
    {
        return AcademicLevel::query()->orderBy('study_level')->orderBy('sort_order')->get();
    }

    public function intakesList()
    {
        return Intake::query()
            ->with('term')
            ->get()
            ->sortBy(fn (Intake $intake) => [AdmissionEntryRules::entryModeRank($intake->entry_mode), -$intake->id])
            ->values();
    }

    public function olevelSubjectsList()
    {
        return OlevelSubject::query()->orderBy('name')->get();
    }

    public function storeLevel(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'study_level' => 'required|in:undergraduate,postgraduate',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('academic.store_level', null, $data, 'Create level', function () use ($data) {
            $level = AcademicLevel::query()->create($data);
            $this->audit->record('academic_level.created', 'Academic level created', 'academic', 'academic_level', $level->id, null, $level);

            return $level;
        });
    }

    public function updateLevel(Request $request, AcademicLevel $academicLevel)
    {
        $before = $academicLevel->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'study_level' => 'sometimes|in:undergraduate,postgraduate',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('academic.update_level', $academicLevel, ['academic_level_id' => $academicLevel->id, ...$data], 'Update level', function () use ($academicLevel, $data, $before) {
            $academicLevel->update($data);
            $this->audit->record('academic_level.updated', 'Academic level updated', 'academic', 'academic_level', $academicLevel->id, $before, $academicLevel);

            return $academicLevel;
        });
    }

    public function destroyLevel(AcademicLevel $academicLevel)
    {
        $before = $academicLevel->toArray();

        return $this->officeGate('academic.destroy_level', $academicLevel, ['academic_level_id' => $academicLevel->id], 'Delete level', function () use ($academicLevel, $before) {
            $academicLevel->delete();
            $this->audit->record('academic_level.deleted', 'Academic level deleted', 'academic', 'academic_level', $academicLevel->id, $before, null);

            return response()->noContent();
        });
    }

    public function importFacultiesTemplate(): StreamedResponse
    {
        return $this->catalogImportTemplate('colleges');
    }

    public function importFaculties(Request $request)
    {
        return $this->runCatalogImport($request, 'colleges', 'faculty.imported', 'Colleges imported');
    }

    public function importDepartmentsTemplate(): StreamedResponse
    {
        return $this->catalogImportTemplate('departments');
    }

    public function importDepartments(Request $request)
    {
        return $this->runCatalogImport($request, 'departments', 'department.imported', 'Departments imported');
    }

    public function importOlevelTemplate(): StreamedResponse
    {
        return $this->catalogImportTemplate('olevel');
    }

    public function importOlevel(Request $request)
    {
        return $this->runCatalogImport($request, 'olevel', 'olevel_subject.imported', "O'level subjects imported");
    }

    public function storeOlevelSubject(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('academic.store_olevel', null, $data, "Create O'level subject", function () use ($data) {
            $subject = OlevelSubject::query()->create($data);
            $this->audit->record('olevel_subject.created', "O'level subject created", 'academic', 'olevel_subject', $subject->id, null, $subject);

            return $subject;
        });
    }

    public function updateOlevelSubject(Request $request, OlevelSubject $olevelSubject)
    {
        $before = $olevelSubject->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('academic.update_olevel', $olevelSubject, ['olevel_subject_id' => $olevelSubject->id, ...$data], "Update O'level subject", function () use ($olevelSubject, $data, $before) {
            $olevelSubject->update($data);
            $this->audit->record('olevel_subject.updated', "O'level subject updated", 'academic', 'olevel_subject', $olevelSubject->id, $before, $olevelSubject);

            return $olevelSubject;
        });
    }

    public function destroyOlevelSubject(OlevelSubject $olevelSubject)
    {
        $before = $olevelSubject->toArray();

        return $this->officeGate('academic.destroy_olevel', $olevelSubject, ['olevel_subject_id' => $olevelSubject->id], "Delete O'level subject", function () use ($olevelSubject, $before) {
            $olevelSubject->delete();
            $this->audit->record('olevel_subject.deleted', "O'level subject deleted", 'academic', 'olevel_subject', $olevelSubject->id, $before, null);

            return response()->noContent();
        });
    }

    public function storeIntake(Request $request)
    {
        $validated = $request->validate([
            'academic_term_id' => 'required|exists:academic_terms,id',
            'name' => 'required|string',
            'entry_mode' => 'required|in:utme,de,jupeb,transfer,pg',
            'opens_on' => 'required|date',
            'closes_on' => 'required|date|after_or_equal:opens_on',
            'application_fee_amount' => 'required|numeric|min:0',
            'acceptance_fee_amount' => 'nullable|numeric|min:0',
            'is_open' => 'sometimes|boolean',
        ]);
        $validated['is_open'] = $request->boolean('is_open', true);

        return $this->officeGate('academic.store_intake', null, $validated, 'Create application session', function () use ($validated) {
            $intake = Intake::query()->create($validated);
            $this->audit->record('intake.created', 'Admission intake created', 'admissions', 'intake', $intake->id, null, $intake);

            return $intake->load('term');
        });
    }

    public function updateIntake(Request $request, Intake $intake)
    {
        $before = $intake->toArray();
        $validated = $request->validate([
            'academic_term_id' => 'sometimes|exists:academic_terms,id',
            'name' => 'sometimes|string',
            'entry_mode' => 'sometimes|in:utme,de,jupeb,transfer,pg',
            'opens_on' => 'sometimes|date',
            'closes_on' => 'sometimes|date|after_or_equal:opens_on',
            'application_fee_amount' => 'sometimes|numeric|min:0',
            'acceptance_fee_amount' => 'nullable|numeric|min:0',
            'is_open' => 'sometimes|boolean',
        ]);
        if ($request->has('is_open')) {
            $validated['is_open'] = $request->boolean('is_open');
        }

        return $this->officeGate('academic.update_intake', $intake, ['intake_id' => $intake->id, ...$validated], 'Update application session', function () use ($intake, $validated, $before) {
            $intake->update($validated);
            $this->audit->record('intake.updated', 'Admission intake updated', 'admissions', 'intake', $intake->id, $before, $intake);

            return $intake->fresh('term');
        });
    }

    public function destroyIntake(Intake $intake)
    {
        $before = $intake->toArray();

        return $this->officeGate('academic.destroy_intake', $intake, ['intake_id' => $intake->id], 'Delete application session', function () use ($intake, $before) {
            $intake->delete();
            $this->audit->record('intake.deleted', 'Admission intake deleted', 'admissions', 'intake', $intake->id, $before, null);

            return response()->noContent();
        });
    }

    public function openIntakes()
    {
        return Intake::query()
            ->with('term')
            ->get()
            ->filter(fn (Intake $intake) => $intake->isAcceptingApplications())
            ->sortBy(fn (Intake $intake) => [AdmissionEntryRules::entryModeRank($intake->entry_mode), $intake->name])
            ->values();
    }

    public function levels()
    {
        return AcademicLevel::query()
            ->where('is_active', true)
            ->orderBy('study_level')
            ->orderBy('sort_order')
            ->get();
    }

    public function olevelSubjects()
    {
        return OlevelSubject::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
