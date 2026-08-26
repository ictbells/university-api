<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\Setting;
use App\Services\AuditWriter;
use App\Services\PremblyService;
use App\Services\SessionCloseService;
use App\Support\AdmissionCurrentGate;
use App\Support\AdmissionsContactSettings;
use App\Support\StaffSupportContactSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private AuditWriter $audit,
        private SessionCloseService $sessionClose,
    ) {}

    public function show()
    {
        return [
            'settings' => Setting::query()->pluck('value', 'key'),
            'campuses' => Campus::query()->with('faculties.departments')->get(),
            'terms' => AcademicTerm::query()->with('session')->orderByDesc('is_current')->get(),
            'sessions' => AcademicSession::query()->with('semesters')->orderByDesc('id')->get(),
        ];
    }

    public function portalInfo()
    {
        $premblyConfigured = app(PremblyService::class)->isConfigured();

        return response()->json([
            ...AdmissionsContactSettings::all(),
            ...StaffSupportContactSettings::all(),
            'nin_live' => $premblyConfigured,
            'prembly_configured' => $premblyConfigured,
            'applications_open' => Intake::hasAccepting(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'university_name' => 'nullable|string',
            'university_motto' => 'nullable|string',
            'current_term_id' => 'nullable|exists:academic_terms,id',
            'maintenance' => 'nullable|boolean',
        ]);

        return $this->officeGate('institution.update_settings', null, $data, 'Update institution settings', function () use ($data) {
            $before = Setting::query()->pluck('value', 'key');
            foreach ($data as $key => $value) {
                if ($key === 'current_term_id' && $value) {
                    $term = AcademicTerm::query()->findOrFail($value);
                    AdmissionCurrentGate::assertCanSetCurrent($term, 'current_term_id');
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
        });
    }

    public function storeCampus(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        return $this->officeGate('academic.store_campus', null, $data, 'Create campus', function () use ($data) {
            $campus = Campus::query()->create($data);
            $this->audit->record('campus.created', 'Campus created', 'institution', 'campus', $campus->id, null, $campus);

            return $campus;
        });
    }

    public function updateCampus(Request $request, Campus $campus)
    {
        $before = $campus->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('academic.update_campus', $campus, ['campus_id' => $campus->id, ...$data], 'Update campus', function () use ($campus, $data, $before) {
            $campus->update($data);
            $this->audit->record('campus.updated', 'Campus updated', 'institution', 'campus', $campus->id, $before, $campus);

            return $campus;
        });
    }

    public function destroyCampus(Campus $campus)
    {
        $before = $campus->toArray();

        return $this->officeGate('academic.destroy_campus', $campus, ['campus_id' => $campus->id], 'Delete campus', function () use ($campus, $before) {
            $campus->delete();
            $this->audit->record('campus.deleted', 'Campus deleted', 'institution', 'campus', $campus->id, $before, null);

            return response()->noContent();
        });
    }

    public function storeFaculty(Request $request)
    {
        $data = $request->validate([
            'campus_id' => 'required|exists:campuses,id',
            'name' => 'required|string',
            'code' => 'nullable|string',
        ]);

        return $this->officeGate('academic.store_faculty', null, $data, 'Create college', function () use ($data) {
            $faculty = Faculty::query()->create($data);
            $this->audit->record('faculty.created', 'College created', 'institution', 'faculty', $faculty->id, null, $faculty);

            return $faculty->load('campus');
        });
    }

    public function updateFaculty(Request $request, Faculty $faculty)
    {
        $before = $faculty->toArray();
        $data = $request->validate([
            'campus_id' => 'sometimes|exists:campuses,id',
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
        ]);

        return $this->officeGate('academic.update_faculty', $faculty, ['faculty_id' => $faculty->id, ...$data], 'Update college', function () use ($faculty, $data, $before) {
            $faculty->update($data);
            $this->audit->record('faculty.updated', 'College updated', 'institution', 'faculty', $faculty->id, $before, $faculty);

            return $faculty->fresh('campus');
        });
    }

    public function destroyFaculty(Faculty $faculty)
    {
        $before = $faculty->toArray();

        return $this->officeGate('academic.destroy_faculty', $faculty, ['faculty_id' => $faculty->id], 'Delete college', function () use ($faculty, $before) {
            $faculty->delete();
            $this->audit->record('faculty.deleted', 'College deleted', 'institution', 'faculty', $faculty->id, $before, null);

            return response()->noContent();
        });
    }

    public function storeDepartment(Request $request)
    {
        $data = $request->validate([
            'faculty_id' => 'required|exists:faculties,id',
            'name' => 'required|string',
            'code' => 'nullable|string',
        ]);

        return $this->officeGate('academic.store_department', null, $data, 'Create academic department', function () use ($data) {
            $department = Department::query()->create($data);
            $this->audit->record('department.created', 'Department created', 'institution', 'department', $department->id, null, $department);

            return $department->load('faculty.campus');
        });
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $before = $department->toArray();
        $data = $request->validate([
            'faculty_id' => 'sometimes|exists:faculties,id',
            'name' => 'sometimes|string',
            'code' => 'nullable|string',
        ]);

        return $this->officeGate('academic.update_department', $department, ['department_id' => $department->id, ...$data], 'Update academic department', function () use ($department, $data, $before) {
            $department->update($data);
            $this->audit->record('department.updated', 'Department updated', 'institution', 'department', $department->id, $before, $department);

            return $department->fresh('faculty.campus');
        });
    }

    public function destroyDepartment(Department $department)
    {
        $before = $department->toArray();

        return $this->officeGate('academic.destroy_department', $department, ['department_id' => $department->id], 'Delete academic department', function () use ($department, $before) {
            $department->delete();
            $this->audit->record('department.deleted', 'Department deleted', 'institution', 'department', $department->id, $before, null);

            return response()->noContent();
        });
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
            'extension_price_per_unit' => 'nullable|numeric|min:0',
            'is_current' => 'boolean',
            'auto_schedule' => 'boolean',
        ]);

        $session = AcademicSession::query()->findOrFail($data['academic_session_id']);
        $data['session_label'] = $session->label;

        if (! empty($data['is_current'])) {
            AdmissionCurrentGate::assertCanSetCurrentForSession((int) $data['academic_session_id']);
        }

        return $this->officeGate('academic.store_term', null, $data, 'Create semester', function () use ($data) {
            $term = DB::transaction(function () use ($data) {
                if (! empty($data['is_current'])) {
                    AdmissionCurrentGate::assertCanSetCurrentForSession((int) $data['academic_session_id']);
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
        });
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
            'extension_price_per_unit' => 'nullable|numeric|min:0',
            'is_current' => 'boolean',
            'auto_schedule' => 'boolean',
        ]);

        if (! empty($data['is_current'])) {
            AdmissionCurrentGate::assertCanSetCurrent($term);
        }

        return $this->officeGate('academic.update_term', $term, ['term_id' => $term->id, ...$data], 'Update semester', function () use ($term, $data, $before) {
            DB::transaction(function () use ($term, $data) {
                if (! empty($data['is_current'])) {
                    AdmissionCurrentGate::assertCanSetCurrent($term);
                    AcademicTerm::query()->where('id', '!=', $term->id)->update(['is_current' => false]);
                    Setting::setValue('current_term_id', $term->id);
                }
                $term->update($data);
            });

            $this->audit->record('term.updated', 'Semester updated', 'institution', 'academic_term', $term->id, $before, $term);

            return $term->fresh('session');
        });
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

        return $this->officeGate('academic.destroy_term', $term, ['term_id' => $term->id], 'Delete semester', function () use ($term, $before) {
            $term->delete();
            $this->audit->record('term.deleted', 'Semester deleted', 'institution', 'academic_term', $term->id, $before, null);

            return response()->noContent();
        });
    }

    public function storeSession(Request $request)
    {
        $data = $request->validate([
            'label' => 'required|string|unique:academic_sessions,label',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'auto_close_on_end' => 'boolean',
            'semesters' => 'required|array|min:2',
            'semesters.*.name' => 'required|string',
            'semesters.*.starts_on' => 'nullable|date',
            'semesters.*.ends_on' => 'nullable|date',
            'semesters.*.normal_registration_closes_at' => 'nullable|date',
            'semesters.*.late_registration_closes_at' => 'nullable|date',
            'semesters.*.extension_price_per_unit' => 'nullable|numeric|min:0',
            'semesters.*.is_current' => 'boolean',
            'semesters.*.auto_schedule' => 'boolean',
        ]);

        $currentCount = collect($data['semesters'])->where('is_current', true)->count();
        if ($currentCount > 1) {
            throw ValidationException::withMessages([
                'semesters' => 'Only one semester can be marked as current.',
            ]);
        }

        return $this->officeGate('academic.store_session', null, $data, 'Create academic session', function () use ($data) {
            $session = DB::transaction(function () use ($data) {
                $session = AcademicSession::query()->create([
                    'label' => $data['label'],
                    'starts_on' => $data['starts_on'] ?? null,
                    'ends_on' => $data['ends_on'] ?? null,
                    'auto_close_on_end' => ! empty($data['auto_close_on_end']),
                ]);

                $hasCurrent = collect($data['semesters'])->contains(fn ($s) => ! empty($s['is_current']));
                if ($hasCurrent) {
                    AdmissionCurrentGate::assertCanSetCurrentForSession((int) $session->id, 'semesters');
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
                        'extension_price_per_unit' => $semester['extension_price_per_unit'] ?? null,
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
        });
    }

    public function updateSession(Request $request, AcademicSession $session)
    {
        $before = $session->toArray();
        $data = $request->validate([
            'label' => 'sometimes|string|unique:academic_sessions,label,'.$session->id,
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'auto_close_on_end' => 'boolean',
        ]);

        return $this->officeGate('academic.update_session', $session, ['session_id' => $session->id, ...$data], 'Update academic session', function () use ($session, $data, $before) {
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
        });
    }

    public function destroySession(AcademicSession $session)
    {
        if ($session->isClosed()) {
            throw ValidationException::withMessages([
                'session' => ['Closed academic sessions cannot be deleted.'],
            ]);
        }

        $before = $session->load('semesters')->toArray();

        return $this->officeGate('academic.destroy_session', $session, ['session_id' => $session->id], 'Delete academic session', function () use ($session, $before) {
            DB::transaction(function () use ($session) {
                $session->semesters()->each(fn (AcademicTerm $term) => $term->delete());
                $session->delete();
            });
            $this->audit->record('session.deleted', 'Academic session deleted', 'institution', 'academic_session', $session->id, $before, null);

            return response()->noContent();
        });
    }

    public function closeSessionPreview(AcademicSession $session)
    {
        abort_unless(
            request()->user()?->hasPermission('academic.sessions.close')
            || request()->user()?->hasPermission('academic.sessions.manage'),
            403,
        );

        return $this->sessionClose->preview($session);
    }

    public function closeSession(AcademicSession $session)
    {
        abort_unless(
            request()->user()?->hasPermission('academic.sessions.close')
            || request()->user()?->hasPermission('academic.sessions.manage'),
            403,
        );

        return $this->officeGate(
            'academic.close_session',
            $session,
            ['session_id' => $session->id],
            'Close academic session '.$session->label,
            fn () => $this->sessionClose->close($session, 'manual', request()->user()),
        );
    }
}

