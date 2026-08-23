<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Setting;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class InstitutionController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function show()
    {
        return [
            'settings' => Setting::query()->pluck('value', 'key'),
            'campuses' => Campus::query()->with('faculties.departments')->get(),
            'terms' => AcademicTerm::query()->orderByDesc('is_current')->get(),
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
            'name' => 'required|string',
            'session_label' => 'required|string',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'is_current' => 'boolean',
        ]);
        if (! empty($data['is_current'])) {
            AcademicTerm::query()->update(['is_current' => false]);
        }
        $term = AcademicTerm::query()->create($data);
        if (! empty($data['is_current'])) {
            Setting::setValue('current_term_id', $term->id);
        }
        $this->audit->record('term.created', 'Academic term created', 'institution', 'academic_term', $term->id, null, $term);

        return $term;
    }

    public function updateTerm(Request $request, AcademicTerm $term)
    {
        $before = $term->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string',
            'session_label' => 'sometimes|string',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'is_current' => 'boolean',
        ]);
        if (! empty($data['is_current'])) {
            AcademicTerm::query()->where('id', '!=', $term->id)->update(['is_current' => false]);
            Setting::setValue('current_term_id', $term->id);
        }
        $term->update($data);
        $this->audit->record('term.updated', 'Academic term updated', 'institution', 'academic_term', $term->id, $before, $term);

        return $term;
    }

    public function destroyTerm(AcademicTerm $term)
    {
        $before = $term->toArray();
        $term->delete();
        $this->audit->record('term.deleted', 'Academic term deleted', 'institution', 'academic_term', $term->id, $before, null);

        return response()->noContent();
    }
}
