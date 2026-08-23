<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function index(Request $request)
    {
        if (! $request->user()->hasPermission('students.view_any')) {
            $student = $request->user()->student;
            abort_unless($student, 404);

            return $student->load(['program', 'wallet', 'user']);
        }

        return Student::query()->with(['program', 'user', 'wallet'])->latest()->paginate(25);
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
        $student->update($data);
        $this->audit->record('student.updated', 'Student profile updated', 'sis', 'student', $student->id, $before, $student->fresh());

        return $student->fresh();
    }
}
