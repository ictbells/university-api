<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\GraduationService;
use Illuminate\Http\Request;

class GraduationController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private GraduationService $graduation,
    ) {}

    public function candidates(Request $request)
    {
        abort_unless($request->user()?->hasPermission('academic.graduate'), 403);

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));

        return $this->graduation->candidates(
            $request->filled('program_id') ? (int) $request->input('program_id') : null,
            $request->filled('campus_id') ? (int) $request->input('campus_id') : null,
            $request->input('search'),
            $perPage,
            $request->filled('academic_session_id') ? (int) $request->input('academic_session_id') : null,
            $request->filled('level') ? (string) $request->input('level') : null,
        );
    }

    public function confer(Request $request)
    {
        abort_unless($request->user()?->hasPermission('academic.graduate'), 403);

        $data = $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'integer|exists:students,id',
            'graduated_at' => 'required|date',
            'academic_session_id' => 'nullable|exists:academic_sessions,id',
            'require_final_year' => 'sometimes|boolean',
        ]);

        $requireFinalYear = $data['require_final_year'] ?? true;
        $ids = $data['student_ids'];
        $subject = count($ids) === 1 ? Student::query()->find($ids[0]) : null;

        return $this->officeGate(
            'academic.graduate',
            $subject,
            $data,
            count($ids) === 1
                ? 'Confirm graduation for one student'
                : 'Confirm graduation for '.count($ids).' students',
            fn () => $this->graduation->confer(
                $ids,
                $data['graduated_at'],
                isset($data['academic_session_id']) ? (int) $data['academic_session_id'] : null,
                $request->user(),
                (bool) $requireFinalYear,
            ),
            'graduation',
        );
    }

    public function conferOne(Request $request, Student $student)
    {
        $request->merge([
            'student_ids' => [$student->id],
            'require_final_year' => $request->boolean('require_final_year', false),
        ]);

        return $this->confer($request);
    }
}
