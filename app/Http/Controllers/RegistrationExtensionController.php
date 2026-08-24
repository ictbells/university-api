<?php

namespace App\Http\Controllers;

use App\Models\RegistrationExtension;
use App\Services\CourseRegistrationService;
use Illuminate\Http\Request;

class RegistrationExtensionController extends Controller
{
    public function __construct(private CourseRegistrationService $registration) {}

    public function index(Request $request)
    {
        $query = RegistrationExtension::query()
            ->with([
                'student.user:id,name,email',
                'student.program:id,name,code',
                'term',
                'invoice',
                'reviewer:id,name',
            ])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('academic_term_id')) {
            $query->where('academic_term_id', (int) $request->academic_term_id);
        }

        return $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));
    }

    public function review(Request $request, RegistrationExtension $extension)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'approved_units' => 'nullable|integer|min:1|max:50',
            'staff_note' => 'nullable|string|max:500',
        ]);

        return $this->registration->reviewExtension(
            $extension,
            $data['decision'],
            $request->user(),
            isset($data['approved_units']) ? (int) $data['approved_units'] : null,
            $data['staff_note'] ?? null,
        );
    }
}
