<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\ExamClearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamClearanceController extends Controller
{
    public function __construct(private ExamClearanceService $clearance) {}

    public function mine(Request $request): JsonResponse
    {
        $student = $request->user()?->student;
        abort_unless($student, 404, 'Student record not found.');

        $student->loadMissing('program');

        return response()->json($this->payload($student));
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('exam_clearance.view')
                || $request->user()?->id === $student->user_id,
            403
        );

        $student->loadMissing('program');

        return response()->json($this->payload($student));
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('exam_clearance.view'), 403);

        $query = Student::query()->with('program:id,name')->latest('id');
        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('matric_number', 'like', $term)
                    ->orWhere('student_number', 'like', $term);
            });
        }

        $page = $query->paginate(min(100, max(10, (int) $request->input('per_page', 25))));
        $page->getCollection()->transform(function (Student $student) {
            return $this->clearance->summarize($student);
        });

        $filter = $request->input('status');
        if (in_array($filter, ['cleared', 'not_cleared'], true)) {
            $filtered = $page->getCollection()->filter(fn (array $row) => $row['status'] === $filter)->values();
            $page->setCollection($filtered);
        }

        return response()->json($page);
    }

    private function payload(Student $student): array
    {
        $name = trim(implode(' ', array_filter([
            $student->first_name,
            $student->middle_name,
            $student->last_name,
        ])));

        return [
            'student' => [
                'id' => $student->id,
                'name' => $name,
                'matric_number' => $student->matric_number,
                'student_number' => $student->student_number,
                'program' => $student->program?->name,
            ],
            ...$this->clearance->forStudent($student),
        ];
    }
}
