<?php

namespace App\Http\Controllers;

use App\Support\RegistrationCriteria;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('registrations.view'), 403);

        $query = RegistrationCriteria::studentsQuery()
            ->with([
                'user',
                'program.department',
                'application.intake',
                'invoices' => fn ($q) => $q->where('category', 'tuition')->where('status', 'paid')->latest(),
            ]);

        if ($request->filled('entry_mode')) {
            $query->whereHas('application', fn ($q) => $q->where('entry_mode', $request->entry_mode));
        }
        if ($request->filled('entry_modes')) {
            $modes = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
            if ($modes !== []) {
                $query->whereHas('application', fn ($q) => $q->whereIn('entry_mode', $modes));
            }
        }

        return $query->latest()->paginate(25);
    }
}
