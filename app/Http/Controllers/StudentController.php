<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\StudentTermRemark;
use App\Models\StudentTermSanction;
use App\Services\AuditWriter;
use App\Services\StudentTermRemarkService;
use App\Services\StudentTermSanctionService;
use App\Support\GradeExamRemark;
use App\Support\ListSessionLevelFilter;
use App\Support\PhoneNumber;
use App\Support\ResultOfficerScope;
use App\Support\StudentTermSanctionType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(
        private AuditWriter $audit,
        private StudentTermSanctionService $sanctions,
        private StudentTermRemarkService $remarks,
    ) {}

    public function index(Request $request)
    {
        if (! $request->user()->hasPermission('students.view_any')) {
            $student = $request->user()->student;
            abort_unless($student, 404);

            return $student->load(['program', 'wallet', 'user']);
        }

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $query = Student::query()
            ->with(['program:id,name,code', 'user:id,name,email'])
            ->latest();
        ListSessionLevelFilter::applyToStudents($query, $request);

        $status = (string) $request->input('status', '');
        if ($status === 'current' || $status === '') {
            $query->whereIn('status', \App\Support\Studentship::CURRENT_STATUSES)
                ->where(function ($builder) {
                    $builder->whereNull('studentship_expires_at')
                        ->orWhereDate('studentship_expires_at', '>', now()->toDateString());
                });
        } elseif ($status === 'alumni') {
            $query->where('status', \App\Support\Studentship::STATUS_ALUMNI);
        } elseif (in_array($status, \App\Support\Studentship::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($request->filled('matric')) {
            $key = strtoupper(preg_replace('/\s+/', '', trim((string) $request->input('matric'))) ?: '');
            $query->where(function ($builder) use ($key) {
                $builder->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$key])
                    ->orWhereRaw('UPPER(REPLACE(COALESCE(student_number, ""), " ", "")) = ?', [$key]);
            });
        } elseif ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('matric_number', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhereHas('user', function ($users) use ($term) {
                        $users->where('email', 'like', $term)->orWhere('name', 'like', $term);
                    });
            });
        }

        ListSessionLevelFilter::applyToStudents($query, $request);

        return $query->paginate($perPage);
    }

    public function show(Request $request, Student $student)
    {
        $user = $request->user();
        if ($student->user_id !== $user->id && ! $user->hasPermission('students.view_any')) {
            abort(403);
        }

        return $student->load(['program.department.faculty', 'wallet.credentials', 'user', 'pgRecord']);
    }

    public function update(Request $request, Student $student)
    {
        $user = $request->user();
        if ($student->user_id !== $user->id && ! $user->hasPermission('students.manage')) {
            abort(403);
        }
        $data = $request->validate([
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'next_of_kin' => 'nullable|string',
            'next_of_kin_phone' => PhoneNumber::constraints(required: false),
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'middle_name' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string',
            'nin' => 'nullable|string',
        ]);
        foreach (Student::NIN_LOCKED as $field) {
            if (array_key_exists($field, $data) && $student->user_id === $user->id) {
                unset($data[$field]);
            }
        }
        if (array_key_exists('next_of_kin_phone', $data) && filled($data['next_of_kin_phone'])) {
            $data['next_of_kin_phone'] = PhoneNumber::normalize($data['next_of_kin_phone']);
        }
        $before = $student->only(array_keys($data));

        $execute = function () use ($student, $data, $before) {
            $student->update($data);
            $this->audit->record('student.updated', 'Student profile updated', 'sis', 'student', $student->id, $before, $student->fresh());

            return $student->fresh();
        };

        if ($student->user_id !== $user->id) {
            return $this->officeGate('students.update', $student, ['student_id' => $student->id, ...$data], 'Update student record', $execute);
        }

        return $execute();
    }

    public function termMeta(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('students.view_any')
                || $request->user()->hasPermission('students.manage')
                || $request->user()->hasPermission('academic.graduate')
                || $request->user()->hasPermission('results.read')
                || $request->user()->hasPermission('results.write'),
            403,
        );

        return [
            'terms' => AcademicTerm::query()
                ->with('session:id,label')
                ->orderByDesc('is_current')
                ->orderByDesc('id')
                ->get(['id', 'academic_session_id', 'name', 'session_label', 'is_current']),
            'types' => collect(StudentTermSanctionType::all())->map(fn (string $type) => [
                'value' => $type,
                'label' => ucfirst($type),
            ])->all(),
            'remark_types' => collect(GradeExamRemark::adminTypes())->map(fn (string $type) => [
                'value' => $type,
                'label' => GradeExamRemark::label($type),
            ])->all(),
        ];
    }

    public function storeTermSanction(Request $request, Student $student)
    {
        $this->assertCanSanction($request->user());
        $data = $request->validate([
            'academic_term_id' => 'required|integer|exists:academic_terms,id',
            'type' => ['required', Rule::in(StudentTermSanctionType::all())],
            'note' => 'nullable|string|max:500',
        ]);

        return $this->officeGate(
            'students.term_sanction',
            $student,
            ['student_id' => $student->id, ...$data],
            'Record term sanction',
            fn () => $this->sanctions->apply(
                $student,
                (int) $data['academic_term_id'],
                $data['type'],
                $data['note'] ?? null,
                $request->user(),
            ),
        );
    }

    public function destroyTermSanction(Request $request, Student $student, StudentTermSanction $sanction)
    {
        $this->assertCanSanction($request->user());
        abort_unless((int) $sanction->student_id === (int) $student->id, 404);

        return $this->officeGate(
            'students.lift_term_sanction',
            $student,
            [
                'student_id' => $student->id,
                'student_term_sanction_id' => $sanction->id,
                'sanction_id' => $sanction->id,
            ],
            'Lift term sanction',
            function () use ($sanction) {
                $this->sanctions->lift($sanction);

                return ['message' => 'Sanction lifted.'];
            },
        );
    }

    public function storeTermRemark(Request $request, Student $student)
    {
        $this->assertCanRemark($request->user(), $student);
        $data = $request->validate([
            'academic_term_id' => 'required|integer|exists:academic_terms,id',
            'type' => 'required|string|max:40',
            'note' => 'nullable|string|max:500',
        ]);

        return $this->officeGate(
            'students.term_remark',
            $student,
            ['student_id' => $student->id, ...$data],
            'Record term remark',
            fn () => $this->remarks->apply(
                $student,
                (int) $data['academic_term_id'],
                $data['type'],
                $data['note'] ?? null,
                $request->user(),
            ),
        );
    }

    public function destroyTermRemark(Request $request, Student $student, StudentTermRemark $remark)
    {
        $this->assertCanRemark($request->user(), $student);
        abort_unless((int) $remark->student_id === (int) $student->id, 404);

        return $this->officeGate(
            'students.lift_term_remark',
            $student,
            [
                'student_id' => $student->id,
                'student_term_remark_id' => $remark->id,
                'remark_id' => $remark->id,
            ],
            'Lift term remark',
            function () use ($remark) {
                $this->remarks->lift($remark);

                return ['message' => 'Remark lifted.'];
            },
        );
    }

    private function assertCanRemark($user, Student $student): void
    {
        abort_unless(
            $user->hasPermission('students.manage')
                || $user->hasPermission('academic.graduate')
                || $user->hasPermission('results.write'),
            403,
        );
        if ($user->hasPermission('results.write')
            && ! $user->hasPermission('students.manage')
            && ! $user->hasPermission('academic.graduate')) {
            ResultOfficerScope::assertStudentInScope($user, $student);
        }
    }

    private function assertCanSanction($user): void
    {
        abort_unless(
            $user->hasPermission('students.manage') || $user->hasPermission('academic.graduate'),
            403,
        );
    }
}
