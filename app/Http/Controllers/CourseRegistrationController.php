<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\CourseRegistrationService;
use App\Support\RegistrationCriteria;
use Illuminate\Http\Request;

class CourseRegistrationController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private CourseRegistrationService $registration) {}

    public function context(Request $request)
    {
        $student = $this->resolveStudent($request, staff: true);

        return $this->registration->context($student, forStaff: true);
    }

    public function searchStudents(Request $request)
    {
        $term = trim((string) $request->input('search', $request->input('q', '')));
        $query = Student::query()
            ->whereHas('application', fn ($application) => RegistrationCriteria::completedApplication($application))
            ->with(['user:id,name,email', 'program:id,name,code']);

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('matric_number', 'like', $like)
                    ->orWhere('student_number', 'like', $like)
                    ->orWhereHas('user', fn ($users) => $users->where('email', 'like', $like)->orWhere('name', 'like', $like));
            });
        }

        return $query->orderBy('last_name')->limit(20)->get();
    }

    public function staffRegister(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_offering_id' => 'required|exists:course_offerings,id',
            'reason' => 'nullable|string|max:500',
        ]);
        $student = Student::query()->findOrFail($data['student_id']);
        $offering = CourseOffering::query()->findOrFail($data['course_offering_id']);

        return $this->officeGate('academic.staff_register', $student, $data, 'Staff course registration', function () use ($student, $offering, $request, $data) {
            return $this->registration->register($student, $offering, $request->user(), true, $data['reason'] ?? null);
        });
    }

    public function staffDrop(Request $request, Enrollment $enrollment)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->officeGate('academic.staff_drop', $enrollment, ['enrollment_id' => $enrollment->id, ...$data], 'Staff course drop', function () use ($enrollment, $request, $data) {
            return $this->registration->drop($enrollment, $request->user(), true, $data['reason'] ?? null);
        });
    }

    public function grantGrace(Request $request)
    {
        abort_unless($request->user()->hasPermission('academic.enrollments.grace'), 403);
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
            'bucket' => 'required|in:general,faculty,departmental,overall',
            'extra_units' => 'required|integer|min:1|max:30',
            'reason' => 'required|string|max:500',
        ]);
        $student = Student::query()->findOrFail($data['student_id']);
        $term = ! empty($data['academic_term_id'])
            ? AcademicTerm::query()->findOrFail($data['academic_term_id'])
            : $this->registration->currentTerm();
        abort_unless($term, 422, 'No current semester is set.');

        return $this->officeGate('academic.grant_grace', $student, $data, 'Grant grace units', function () use ($student, $term, $data, $request) {
            return $this->registration->grantGrace(
                $student,
                $term,
                $data['bucket'],
                (int) $data['extra_units'],
                $data['reason'],
                $request->user(),
            );
        });
    }

    public function myContext(Request $request)
    {
        $this->denyStaff($request);

        return $this->registration->context($this->student($request));
    }

    public function myRegister(Request $request)
    {
        $this->denyStaff($request);
        $data = $request->validate([
            'course_offering_id' => 'required|exists:course_offerings,id',
        ]);
        $offering = CourseOffering::query()->findOrFail($data['course_offering_id']);

        return $this->registration->register($this->student($request), $offering, $request->user(), false);
    }

    public function myDrop(Request $request, Enrollment $enrollment)
    {
        $this->denyStaff($request);
        abort_unless($enrollment->student_id === $this->student($request)->id, 403);

        return $this->registration->drop($enrollment, $request->user(), false);
    }

    public function myExtension(Request $request)
    {
        $this->denyStaff($request);
        $context = $this->registration->context($this->student($request), ensureCarryOvers: false);

        return $context['extension'];
    }

    public function requestExtension(Request $request)
    {
        $this->denyStaff($request);
        $data = $request->validate([
            'requested_units' => 'required|integer|min:1|max:50',
            'reason' => 'required|string|max:500',
        ]);
        $term = $this->registration->currentTerm();
        abort_unless($term, 422, 'No current semester is set.');

        return $this->registration->requestExtension(
            $this->student($request),
            $term,
            (int) $data['requested_units'],
            $data['reason'],
            $request->user(),
        );
    }

    private function student(Request $request): Student
    {
        $student = $request->user()->student;
        abort_unless($student, 404, 'No student record is linked to this account.');

        return $student;
    }

    private function denyStaff(Request $request): void
    {
        abort_if($request->user()->isStaffPortalUser(), 403, 'Staff accounts cannot use student registration routes.');
    }

    private function resolveStudent(Request $request, bool $staff = false): Student
    {
        if ($staff) {
            $id = (int) $request->input('student_id');
            abort_unless($id, 422, 'Select a student.');

            return Student::query()->findOrFail($id);
        }

        return $this->student($request);
    }
}
