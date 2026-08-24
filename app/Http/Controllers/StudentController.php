<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(private AuditWriter $audit) {}

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
            'next_of_kin_phone' => 'nullable|string',
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
}
