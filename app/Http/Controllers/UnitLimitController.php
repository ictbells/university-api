<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\UnitLimit;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class UnitLimitController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function meta()
    {
        return [
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'levels' => AcademicLevel::query()->orderBy('sort_order')->get(['id', 'name', 'code']),
            'terms' => AcademicTerm::query()->orderByDesc('is_current')->orderByDesc('id')->get(['id', 'name', 'session_label', 'is_current']),
        ];
    }

    public function index(Request $request)
    {
        $query = UnitLimit::query()->with(['program:id,name,code', 'level:id,name,code', 'term:id,name,session_label']);
        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->program_id);
        }
        if ($request->filled('academic_term_id')) {
            $query->where('academic_term_id', (int) $request->academic_term_id);
        }

        return $query->orderBy('program_id')->orderBy('bucket')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $limit = UnitLimit::query()->create($data);
        $this->audit->record('unit_limit.created', 'Unit limit created', 'academic', 'unit_limit', $limit->id, null, $limit);

        return $limit->load(['program', 'level', 'term']);
    }

    public function update(Request $request, UnitLimit $unitLimit)
    {
        $before = $unitLimit->toArray();
        $unitLimit->update($this->validated($request, false));
        $this->audit->record('unit_limit.updated', 'Unit limit updated', 'academic', 'unit_limit', $unitLimit->id, $before, $unitLimit);

        return $unitLimit->fresh(['program', 'level', 'term']);
    }

    public function destroy(UnitLimit $unitLimit)
    {
        $before = $unitLimit->toArray();
        $unitLimit->delete();
        $this->audit->record('unit_limit.deleted', 'Unit limit deleted', 'academic', 'unit_limit', $unitLimit->id, $before, null);

        return response()->noContent();
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'program_id' => ($creating ? 'required' : 'sometimes').'|exists:programs,id',
            'academic_level_id' => 'nullable|exists:academic_levels,id',
            'academic_term_id' => 'nullable|exists:academic_terms,id',
            'bucket' => ($creating ? 'required' : 'sometimes').'|in:general,faculty,departmental,overall',
            'min_units' => 'required|integer|min:0|max:50',
            'max_units' => 'required|integer|min:0|max:50|gte:min_units',
        ]);
    }
}
