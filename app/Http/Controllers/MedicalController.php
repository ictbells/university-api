<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\Immunization;
use App\Models\MedicalProfile;
use App\Models\SickNote;
use App\Models\Student;
use App\Services\AuditWriter;
use App\Services\ClinicBillingService;
use App\Support\ClinicSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MedicalController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(
        private ClinicBillingService $billing,
        private AuditWriter $audit,
    ) {}

    public function nhisRoster(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('medical.view_any')
            || $request->user()->hasPermission('medical.manage'),
            403
        );

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $status = (string) $request->input('status', 'all');
        $query = Student::query()
            ->with(['medicalProfile', 'program:id,name,code', 'user:id,name,email'])
            ->whereIn('status', \App\Support\Studentship::CURRENT_STATUSES)
            ->where(function ($builder) {
                $builder->whereNull('studentship_expires_at')
                    ->orWhereDate('studentship_expires_at', '>', now()->toDateString());
            })
            ->latest('id');

        if ($status === 'enrolled') {
            $query->whereHas('medicalProfile', fn ($q) => $q->where('nhis_enrolled', true));
        } elseif ($status === 'not_enrolled') {
            $query->where(function ($builder) {
                $builder->whereDoesntHave('medicalProfile')
                    ->orWhereHas('medicalProfile', fn ($q) => $q->where('nhis_enrolled', false));
            });
        } elseif ($status === 'expiring') {
            $query->whereHas('medicalProfile', function ($q) {
                $q->where('nhis_enrolled', true)
                    ->whereNotNull('nhis_valid_until')
                    ->whereDate('nhis_valid_until', '<=', now()->addDays(30)->toDateString());
            });
        }

        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('matric_number', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhereHas('medicalProfile', function ($profiles) use ($term) {
                        $profiles->where('nhis_number', 'like', $term)
                            ->orWhere('nhis_provider', 'like', $term);
                    })
                    ->orWhereHas('user', function ($users) use ($term) {
                        $users->where('email', 'like', $term)->orWhere('name', 'like', $term);
                    });
            });
        }

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function (Student $student) {
            $profile = $student->medicalProfile;
            $cover = $profile
                ? $this->billing->describeCoverage($profile)
                : ['mode' => 'none', 'percent' => 0.0, 'amount' => null];
            $student->setAttribute('effective_coverage_percent', $cover['percent']);
            $student->setAttribute('effective_coverage_amount', $cover['amount']);
            $student->setAttribute('coverage_mode', $cover['mode']);

            return $student;
        });

        return [
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'enrolled' => MedicalProfile::query()->where('nhis_enrolled', true)->count(),
                'settings' => ClinicSettings::all(),
            ],
        ];
    }

    public function profile(Request $request, Student $student)
    {
        $this->authorizeMedical($request, $student);
        $forStudent = $student->user_id === $request->user()->id
            && ! $request->user()->hasPermission('medical.view_any')
            && ! $request->user()->hasPermission('medical.manage');

        $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]);
        $visitsQuery = ClinicVisit::query()
            ->where('student_id', $student->id)
            ->with(['bill.invoice', 'items', 'prescriptions', 'sickNotes'])
            ->latest();

        $visits = $visitsQuery->get();
        if ($forStudent) {
            $visits = $visits->map(function (ClinicVisit $visit) {
                $row = $visit->toArray();
                if ($visit->notes_internal) {
                    $row['notes'] = null;
                }

                return $row;
            });
        }

        $cover = $this->billing->describeCoverage($profile);

        return [
            'profile' => $profile,
            'settings' => ClinicSettings::all(),
            'effective_coverage_percent' => $cover['percent'],
            'effective_coverage_amount' => $cover['amount'],
            'coverage_mode' => $cover['mode'],
            'immunizations' => Immunization::query()->where('student_id', $student->id)->orderByDesc('given_on')->get(),
            'visits' => $visits,
            'sick_notes' => SickNote::query()->where('student_id', $student->id)->latest()->get(),
            'student' => $student->only(['id', 'first_name', 'last_name', 'matric_number', 'student_number']),
        ];
    }

    public function enrolByMatric(Request $request)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $this->validatedProfileData($request, nhisOnly: true);
        $student = $this->findStudentByMatric((string) $request->input('matric_number'));
        abort_unless($student, 422, 'No student found with that matric number.');

        return $this->persistProfile($student, $data);
    }

    public function updateProfile(Request $request, Student $student)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $this->validatedProfileData($request, nhisOnly: false);

        return $this->persistProfile($student, $data);
    }

    public function addImmunization(Request $request, Student $student)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);

        $data = $request->validate([
            'vaccine' => 'required|string',
            'given_on' => 'nullable|date',
        ]);

        return $this->officeGate(
            'medical.add_immunization',
            $student,
            ['student_id' => $student->id, ...$data],
            'Record immunization',
            fn () => Immunization::query()->create($data + ['student_id' => $student->id]),
        );
    }

    public function deleteImmunization(Request $request, Immunization $immunization)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);

        return $this->officeGate(
            'medical.delete_immunization',
            $immunization,
            ['immunization_id' => $immunization->id],
            'Delete immunization',
            function () use ($immunization) {
                $immunization->delete();

                return response()->json(['ok' => true]);
            },
        );
    }

    private function authorizeMedical(Request $request, Student $student): void
    {
        $user = $request->user();
        // Own chart: any matriculated student (no medical.view_own required)
        if ($student->user_id === $user->id) {
            return;
        }
        if ($user->hasPermission('medical.view_any') || $user->hasPermission('medical.manage')) {
            return;
        }
        abort(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProfileData(Request $request, bool $nhisOnly): array
    {
        $rules = [
            'nhis_enrolled' => 'nullable|boolean',
            'nhis_number' => 'nullable|string|max:100',
            'nhis_provider' => 'nullable|string|max:255',
            'nhis_coverage_percent' => 'nullable|numeric|min:0|max:100',
            'nhis_coverage_amount' => 'nullable|numeric|min:0',
            'nhis_valid_until' => 'nullable|date',
        ];
        if ($nhisOnly) {
            $rules['matric_number'] = 'required|string|max:80';
        } else {
            $rules['blood_type'] = 'nullable|string';
            $rules['genotype'] = 'nullable|string|in:AA,AS,AC,SS,SC,CC,Other';
            $rules['has_medical_condition'] = 'nullable|boolean';
            $rules['allergies'] = 'nullable|string';
            $rules['conditions'] = 'nullable|string';
        }

        $data = $request->validate($rules);
        unset($data['matric_number']);

        if ($request->filled('nhis_coverage_percent') && $request->filled('nhis_coverage_amount')) {
            throw ValidationException::withMessages([
                'nhis_coverage_amount' => ['Enter a coverage percent or a fixed amount, not both.'],
            ]);
        }
        if (array_key_exists('nhis_coverage_amount', $data) && $data['nhis_coverage_amount'] !== null) {
            $data['nhis_coverage_percent'] = null;
        } elseif (array_key_exists('nhis_coverage_percent', $data) && $data['nhis_coverage_percent'] !== null) {
            $data['nhis_coverage_amount'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistProfile(Student $student, array $data): mixed
    {
        $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]);
        $before = $profile->toArray();

        return $this->officeGate(
            'medical.update_profile',
            $student,
            ['student_id' => $student->id, ...$data],
            'Update medical profile',
            function () use ($profile, $data, $before) {
                $profile->update($data);
                $this->audit->record(
                    'medical.profile_updated',
                    'Medical profile updated',
                    'medical',
                    'medical_profile',
                    $profile->id,
                    $before,
                    $profile->fresh()->toArray()
                );

                return $profile->fresh();
            },
        );
    }

    private function findStudentByMatric(string $matric): ?Student
    {
        $key = strtoupper(preg_replace('/\s+/', '', trim($matric)) ?: '');
        if ($key === '') {
            return null;
        }

        return Student::query()
            ->where(function ($builder) use ($key) {
                $builder->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$key])
                    ->orWhereRaw('UPPER(REPLACE(COALESCE(student_number, ""), " ", "")) = ?', [$key]);
            })
            ->first();
    }
}
