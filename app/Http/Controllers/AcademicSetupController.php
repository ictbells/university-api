<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\OlevelSubject;
use App\Models\Program;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class AcademicSetupController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function index()
    {
        return [
            'campuses' => Campus::query()->with('faculties.departments')->orderBy('name')->get(),
            'terms' => AcademicTerm::query()->orderByDesc('is_current')->orderByDesc('id')->get(),
            'programs' => Program::query()->with('department.faculty')->orderBy('name')->get(),
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

    public function departments()
    {
        return Department::query()->with('faculty.campus')->orderBy('name')->get();
    }

    public function terms()
    {
        return AcademicTerm::query()->orderByDesc('is_current')->orderByDesc('id')->get();
    }

    public function programs()
    {
        return Program::query()->with(['department.faculty', 'courses'])->orderBy('name')->get();
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
        return Intake::query()->with('term')->orderByDesc('id')->get();
    }

    public function olevelSubjectsList()
    {
        return OlevelSubject::query()->orderBy('name')->get();
    }

    public function storeLevel(Request $request)
    {
        $level = AcademicLevel::query()->create($request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'study_level' => 'required|in:undergraduate,postgraduate',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]));
        $this->audit->record('academic_level.created', 'Academic level created', 'academic', 'academic_level', $level->id, null, $level);

        return $level;
    }

    public function updateLevel(Request $request, AcademicLevel $academicLevel)
    {
        $before = $academicLevel->toArray();
        $academicLevel->update($request->validate([
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'study_level' => 'sometimes|in:undergraduate,postgraduate',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]));
        $this->audit->record('academic_level.updated', 'Academic level updated', 'academic', 'academic_level', $academicLevel->id, $before, $academicLevel);

        return $academicLevel;
    }

    public function destroyLevel(AcademicLevel $academicLevel)
    {
        $before = $academicLevel->toArray();
        $academicLevel->delete();
        $this->audit->record('academic_level.deleted', 'Academic level deleted', 'academic', 'academic_level', $academicLevel->id, $before, null);

        return response()->noContent();
    }

    public function storeOlevelSubject(Request $request)
    {
        $subject = OlevelSubject::query()->create($request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'is_active' => 'boolean',
        ]));
        $this->audit->record('olevel_subject.created', "O'level subject created", 'academic', 'olevel_subject', $subject->id, null, $subject);

        return $subject;
    }

    public function updateOlevelSubject(Request $request, OlevelSubject $olevelSubject)
    {
        $before = $olevelSubject->toArray();
        $olevelSubject->update($request->validate([
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'is_active' => 'boolean',
        ]));
        $this->audit->record('olevel_subject.updated', "O'level subject updated", 'academic', 'olevel_subject', $olevelSubject->id, $before, $olevelSubject);

        return $olevelSubject;
    }

    public function destroyOlevelSubject(OlevelSubject $olevelSubject)
    {
        $before = $olevelSubject->toArray();
        $olevelSubject->delete();
        $this->audit->record('olevel_subject.deleted', "O'level subject deleted", 'academic', 'olevel_subject', $olevelSubject->id, $before, null);

        return response()->noContent();
    }

    public function storeIntake(Request $request)
    {
        $intake = Intake::query()->create($request->validate([
            'academic_term_id' => 'required|exists:academic_terms,id',
            'name' => 'required|string',
            'entry_mode' => 'required|in:utme,de,jupeb,transfer,pg',
            'opens_on' => 'required|date',
            'closes_on' => 'required|date|after_or_equal:opens_on',
            'is_open' => 'boolean',
        ]));
        $this->audit->record('intake.created', 'Admission intake created', 'admissions', 'intake', $intake->id, null, $intake);

        return $intake->load('term');
    }

    public function updateIntake(Request $request, Intake $intake)
    {
        $before = $intake->toArray();
        $intake->update($request->validate([
            'academic_term_id' => 'sometimes|exists:academic_terms,id',
            'name' => 'sometimes|string',
            'entry_mode' => 'sometimes|in:utme,de,jupeb,transfer,pg',
            'opens_on' => 'sometimes|date',
            'closes_on' => 'sometimes|date|after_or_equal:opens_on',
            'is_open' => 'boolean',
        ]));
        $this->audit->record('intake.updated', 'Admission intake updated', 'admissions', 'intake', $intake->id, $before, $intake);

        return $intake->fresh('term');
    }

    public function destroyIntake(Intake $intake)
    {
        $before = $intake->toArray();
        $intake->delete();
        $this->audit->record('intake.deleted', 'Admission intake deleted', 'admissions', 'intake', $intake->id, $before, null);

        return response()->noContent();
    }

    public function openIntakes()
    {
        return Intake::query()->accepting()->with('term')->orderBy('name')->get();
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
