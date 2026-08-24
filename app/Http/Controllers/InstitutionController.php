<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Setting;
use App\Services\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function show()
    {
        return [
            'settings' => Setting::query()->pluck('value', 'key'),
            'campuses' => Campus::query()->with('faculties.departments')->get(),
            'terms' => AcademicTerm::query()->with('session')->orderByDesc('is_current')->get(),
            'sessions' => AcademicSession::query()->with('semesters')->orderByDesc('id')->get(),
        ];
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'university_name' => 'nullable|string',
            'university_motto' => 'nullable|string',
            'current_term_id' => 'nullable|exists:academic_terms,id',
            'maintenance' => 'nullable|boolean',
        ]);
        $before = Setting::query()->pluck('value', 'key');
        foreach ($data as $key => $value) {
            if ($key === 'current_term_id' && $value) {
                AcademicTerm::query()->update(['is_current' => false]);
                AcademicTerm::query()->where('id', $value)->update(['is_current' => true]);
                Setting::setValue('current_term_id', $value);
            } elseif ($key === 'maintenance') {
                Setting::setValue('maintenance', $value ? '1' : '0');
            } elseif ($value !== null) {
                Setting::setValue($key, $value);
            }
        }
        $this->audit->record('settings.updated', 'Institution settings updated', 'admin', 'setting', 1, $before, Setting::query()->pluck('value', 'key'));

        return $this->show();
    }

    public function storeCampus(Request $request)
    {
        $campus = Campus::query()->create($request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
        ]));
        $this->audit->record('campus.created', 'Campus created', 'institution', 'campus', $campus->id, null, $campus);

        return $campus;
    }

    public function updateCampus(Request $request, Campus $campus)
    {
        $before = $campus->toArray();
        $campus->update($request->validate([
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]));
        $this->audit->record('campus.updated', 'Campus updated', 'institution', 'campus', $campus->id, $before, $campus);

        return $campus;
    }

    public function destroyCampus(Campus $campus)
    {
        $before = $campus->toArray();
        $campus->delete();
        $this->audit->record('campus.deleted', 'Campus deleted', 'institution', 'campus', $campus->id, $before, null);

        return response()->noContent();
    }

    public function storeFaculty(Request $request)
    {
        $faculty = Faculty::query()->create($request->validate([
            'campus_id' => 'required|exists:campuses,id',
            'name' => 'required|string',
            'code' => 'nullable|string',
        ]));
        $this->audit->record('faculty.created', 'College created', 'institution', 'faculty', $faculty->id, null, $faculty);

        return $faculty->load('campus');
    }

    public function updateFaculty(Request $request, Faculty $faculty)
    {
        $before = $faculty->toArray();
        $faculty->update($request->validate([
            'campus_id' => 'sometimes|exists:campuses,id',
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
        ]));
        $this->audit->record('faculty.updated', 'College updated', 'institution', 'faculty', $faculty->id, $before, $faculty);

        return $faculty->fresh('campus');
    }

    public function destroyFaculty(Faculty $faculty)
    {
        $before = $faculty->toArray();
        $faculty->delete();
        $this->audit->record('faculty.deleted', 'College deleted', 'institution', 'faculty', $faculty->id, $before, null);

        return response()->noContent();
    }

    public function storeDepartment(Request $request)
    {
        $department = Department::query()->create($request->validate([
            'faculty_id' => 'required|exists:faculties,id',
            'name' => 'required|string',
            'code' => 'nullable|string',
        ]));
        $this->audit->record('department.created', 'Department created', 'institution', 'department', $department->id, null, $department);

        return $department->load('faculty.campus');
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $before = $department->toArray();
        $department->update($request->validate([
            'faculty_id' => 'sometimes|exists:faculties,id',
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
        ]));
        $this->audit->record('department.updated', 'Department updated', 'institution', 'department', $department->id, $before, $department);

        return $department->fresh('faculty.campus');
    }

    public function destroyDepartment(Department $department)
    {
        $before = $department->toArray();
        $department->delete();
        $this->audit->record('department.deleted', 'Department deleted', 'institution', 'department', $department->id, $before, null);

        return response()->noContent();
    }

    public function storeTerm(Request $request)
    {
        $data = $request->validate([
            'academic_session_id' => 'required|exists:academic_sessions,id',
            'name' => 'required|string',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'normal_registration_closes_at' => 'nullable|date',
            'late_registration_closes_at' => 'nullable|date',
            'is_current' => 'boolean',
            'auto_schedule' => 'boolean',
        ]);

        $session = AcademicSession::query()->findOrFail($data['academic_session_id']);
        $data['session_label'] = $session->label;

        $term = DB::transaction(function () use ($data) {
            if (! empty($data['is_current'])) {
                AcademicTerm::query()->update(['is_current' => false]);
            }
            $term = AcademicTerm::query()->create($data);
            if (! empty($data['is_current'])) {
                Setting::setValue('current_term_id', $term->id);
            }

            return $term;
        });

        $this->audit->record('term.created', 'Semester created', 'institution', 'academic_term', $term->id, null, $term);

        return $term->load('session');
    }

    public function updateTerm(Request $request, AcademicTerm $term)
    {
        $before = $term->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'normal_registration_closes_at' => 'nullable|date',
            'late_registration_closes_at' => 'nullable|date',
            'is_current' => 'boolean',
            'auto_schedule' => 'boolean',
        ]);

        DB::transaction(function () use ($term, $data) {
            if (! empty($data['is_current'])) {
                AcademicTerm::query()->where('id', '!=', $term->id)->update(['is_current' => false]);
                Setting::setValue('current_term_id', $term->id);
            }
            $term->update($data);
        });

        $this->audit->record('term.updated', 'Semester updated', 'institution', 'academic_term', $term->id, $before, $term);

        return $term->fresh('session');
    }

    public function destroyTerm(AcademicTerm $term)
    {
        $session = $term->session;
        $remaining = $session
            ? $session->semesters()->where('id', '!=', $term->id)->count()
            : 0;

        if ($session && $remaining < 2) {
            throw ValidationException::withMessages([
                'term' => 'A session must keep at least two semesters.',
            ]);
        }

        $before = $term->toArray();
        $term->delete();
        $this->audit->record('term.deleted', 'Semester deleted', 'institution', 'academic_term', $term->id, $before, null);

        return response()->noContent();
    }

    public function storeSession(Request $request)
    {
        $data = $request->validate([
            'label' => 'required|string|unique:academic_sessions,label',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'semesters' => 'required|array|min:2',
            'semesters.*.name' => 'required|string',
            'semesters.*.starts_on' => 'nullable|date',
            'semesters.*.ends_on' => 'nullable|date',
            'semesters.*.normal_registration_closes_at' => 'nullable|date',
            'semesters.*.late_registration_closes_at' => 'nullable|date',
            'semesters.*.is_current' => 'boolean',
            'semesters.*.auto_schedule' => 'boolean',
        ]);

        $currentCount = collect($data['semesters'])->where('is_current', true)->count();
        if ($currentCount > 1) {
            throw ValidationException::withMessages([
                'semesters' => 'Only one semester can be marked as current.',
            ]);
        }

        $session = DB::transaction(function () use ($data) {
            $session = AcademicSession::query()->create([
                'label' => $data['label'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
            ]);

            $hasCurrent = collect($data['semesters'])->contains(fn ($s) => ! empty($s['is_current']));
            if ($hasCurrent) {
                AcademicTerm::query()->update(['is_current' => false]);
            }

            foreach ($data['semesters'] as $semester) {
                $term = AcademicTerm::query()->create([
                    'academic_session_id' => $session->id,
                    'session_label' => $session->label,
                    'name' => $semester['name'],
                    'starts_on' => $semester['starts_on'] ?? null,
                    'ends_on' => $semester['ends_on'] ?? null,
                    'normal_registration_closes_at' => $semester['normal_registration_closes_at'] ?? null,
                    'late_registration_closes_at' => $semester['late_registration_closes_at'] ?? null,
                    'is_current' => ! empty($semester['is_current']),
                    'auto_schedule' => array_key_exists('auto_schedule', $semester)
                        ? ! empty($semester['auto_schedule'])
                        : true,
                ]);
                if (! empty($semester['is_current'])) {
                    Setting::setValue('current_term_id', $term->id);
                }
            }

            return $session->load('semesters');
        });

        $this->audit->record('session.created', 'Academic session created', 'institution', 'academic_session', $session->id, null, $session);

        return $session;
    }

    public function updateSession(Request $request, AcademicSession $session)
    {
        $before = $session->toArray();
        $data = $request->validate([
            'label' => 'sometimes|string|unique:academic_sessions,label,'.$session->id,
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
        ]);

        DB::transaction(function () use ($session, $data) {
            $session->update($data);
            if (isset($data['label'])) {
                AcademicTerm::query()
                    ->where('academic_session_id', $session->id)
                    ->update(['session_label' => $data['label']]);
            }
        });

        $this->audit->record('session.updated', 'Academic session updated', 'institution', 'academic_session', $session->id, $before, $session);

        return $session->fresh('semesters');
    }

    public function destroySession(AcademicSession $session)
    {
        $before = $session->load('semesters')->toArray();
        DB::transaction(function () use ($session) {
            $session->semesters()->each(fn (AcademicTerm $term) => $term->delete());
            $session->delete();
        });
        $this->audit->record('session.deleted', 'Academic session deleted', 'institution', 'academic_session', $session->id, $before, null);

        return response()->noContent();
    }
}
