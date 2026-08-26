<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\UnitLimit;
use App\Services\AuditWriter;
use App\Support\ListSessionLevelFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitLimitController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private AuditWriter $audit) {}

    public function meta()
    {
        return [
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code', 'study_level']),
            'levels' => AcademicLevel::query()->orderBy('study_level')->orderBy('sort_order')->get(['id', 'name', 'code', 'study_level']),
            'sessions' => AcademicSession::query()->orderByDesc('id')->get(['id', 'label']),
            'terms' => AcademicTerm::query()
                ->orderBy('academic_session_id')
                ->orderBy('id')
                ->get(['id', 'name', 'session_label', 'academic_session_id', 'is_current']),
        ];
    }

    public function index(Request $request)
    {
        $query = UnitLimit::query()->with(['program:id,name,code', 'level:id,name,code,study_level', 'term:id,name,session_label,academic_session_id']);
        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->program_id);
        }
        if ($request->filled('academic_term_id')) {
            $query->where('academic_term_id', (int) $request->academic_term_id);
        }
        ListSessionLevelFilter::applySessionToTermRelation($query, $request);
        if ($level = ListSessionLevelFilter::levelCode($request)) {
            $query->whereHas('level', fn ($levels) => $levels->where('code', $level));
        }

        return $query->orderBy('program_id')->orderBy('academic_level_id')->orderBy('academic_term_id')->orderBy('bucket')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->officeGate('academic.store_unit_limit', null, $data, 'Create unit limit', function () use ($data) {
            $limit = UnitLimit::query()->create($data);
            $this->audit->record('unit_limit.created', 'Unit limit created', 'academic', 'unit_limit', $limit->id, null, $limit);

            return $limit->load(['program', 'level', 'term']);
        });
    }

    public function update(Request $request, UnitLimit $unitLimit)
    {
        $before = $unitLimit->toArray();

        return $this->officeGate('academic.update_unit_limit', $unitLimit, ['unit_limit_id' => $unitLimit->id, ...$this->validated($request, false)], 'Update unit limit', function () use ($unitLimit, $before, $request) {
            $unitLimit->update($this->validated($request, false));
            $this->audit->record('unit_limit.updated', 'Unit limit updated', 'academic', 'unit_limit', $unitLimit->id, $before, $unitLimit);

            return $unitLimit->fresh(['program', 'level', 'term']);
        });
    }

    public function destroy(UnitLimit $unitLimit)
    {
        $before = $unitLimit->toArray();

        return $this->officeGate('academic.destroy_unit_limit', $unitLimit, ['unit_limit_id' => $unitLimit->id], 'Delete unit limit', function () use ($unitLimit, $before) {
            $unitLimit->delete();
            $this->audit->record('unit_limit.deleted', 'Unit limit deleted', 'academic', 'unit_limit', $unitLimit->id, $before, null);

            return response()->noContent();
        });
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'academic_level_id' => 'nullable|exists:academic_levels,id',
            'academic_term_ids' => 'required|array|min:1',
            'academic_term_ids.*' => 'integer|exists:academic_terms,id',
            'limits' => 'present|array',
            'limits.*.academic_term_id' => 'required|integer|exists:academic_terms,id',
            'limits.*.bucket' => 'required|in:general,faculty,departmental,overall',
            'limits.*.min_units' => 'nullable|integer|min:0|max:50',
            'limits.*.max_units' => 'nullable|integer|min:0|max:50',
        ]);
        $termIds = array_values(array_unique(array_map('intval', $data['academic_term_ids'])));
        foreach ($data['limits'] as $index => $row) {
            if (! in_array((int) $row['academic_term_id'], $termIds, true)) {
                throw ValidationException::withMessages([
                    "limits.$index.academic_term_id" => 'Semester must belong to this schedule.',
                ]);
            }
            $min = $row['min_units'] ?? null;
            $max = $row['max_units'] ?? null;
            if ($min === null && $max === null) {
                continue;
            }
            if ($min === null || $max === null) {
                throw ValidationException::withMessages([
                    "limits.$index.min_units" => 'Enter both minimum and maximum, or leave the cell blank.',
                ]);
            }
            if ((int) $max < (int) $min) {
                throw ValidationException::withMessages([
                    "limits.$index.max_units" => 'Maximum must be at least the minimum.',
                ]);
            }
        }

        return $this->officeGate(
            'academic.sync_unit_limits',
            null,
            $data,
            'Save unit limit schedule',
            function () use ($data, $termIds) {
                $programId = (int) $data['program_id'];
                $levelId = $data['academic_level_id'] ?? null;
                $saved = DB::transaction(function () use ($data, $programId, $levelId, $termIds) {
                    $byKey = [];
                    foreach ($data['limits'] as $row) {
                        $byKey[(int) $row['academic_term_id'].':'.$row['bucket']] = $row;
                    }

                    $touched = [];
                    foreach ($termIds as $termId) {
                        foreach (UnitLimit::BUCKETS as $bucket) {
                            $key = $termId.':'.$bucket;
                            $row = $byKey[$key] ?? null;
                            $existing = $this->matchQuery($programId, $levelId, $termId, $bucket)->first();
                            $min = $row['min_units'] ?? null;
                            $max = $row['max_units'] ?? null;
                            if ($min === null || $max === null) {
                                if ($existing) {
                                    $existing->delete();
                                }

                                continue;
                            }
                            $payload = [
                                'program_id' => $programId,
                                'academic_level_id' => $levelId,
                                'academic_term_id' => $termId,
                                'bucket' => $bucket,
                                'min_units' => (int) $min,
                                'max_units' => (int) $max,
                            ];
                            if ($existing) {
                                $existing->update($payload);
                                $touched[] = $existing->fresh();
                            } else {
                                $touched[] = UnitLimit::query()->create($payload);
                            }
                        }
                    }

                    return $touched;
                });

                $this->audit->record(
                    'unit_limit.synced',
                    'Unit limit schedule saved',
                    'academic',
                    'program',
                    $programId,
                    null,
                    ['academic_level_id' => $levelId, 'academic_term_ids' => $termIds, 'count' => count($saved)],
                );

                return UnitLimit::query()
                    ->with(['program:id,name,code', 'level:id,name,code', 'term:id,name,session_label,academic_session_id'])
                    ->where('program_id', $programId)
                    ->when(
                        $levelId === null,
                        fn ($query) => $query->whereNull('academic_level_id'),
                        fn ($query) => $query->where('academic_level_id', $levelId),
                    )
                    ->whereIn('academic_term_id', $termIds)
                    ->orderBy('academic_term_id')
                    ->orderBy('bucket')
                    ->get();
            },
        );
    }

    public function destroyGroup(Request $request)
    {
        $data = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'academic_level_id' => 'nullable|exists:academic_levels,id',
            'academic_term_ids' => 'required|array|min:1',
            'academic_term_ids.*' => 'integer|exists:academic_terms,id',
        ]);

        return $this->officeGate('academic.destroy_unit_limit_group', null, $data, 'Delete unit limit schedule', function () use ($data) {
            $programId = (int) $data['program_id'];
            $levelId = $data['academic_level_id'] ?? null;
            $termIds = array_values(array_unique(array_map('intval', $data['academic_term_ids'])));
            $query = UnitLimit::query()
                ->where('program_id', $programId)
                ->when(
                    $levelId === null,
                    fn ($inner) => $inner->whereNull('academic_level_id'),
                    fn ($inner) => $inner->where('academic_level_id', $levelId),
                )
                ->whereIn('academic_term_id', $termIds);
            $ids = $query->pluck('id')->all();
            $query->delete();
            $this->audit->record(
                'unit_limit.group_deleted',
                'Unit limit schedule deleted',
                'academic',
                'program',
                $programId,
                ['ids' => $ids],
                null,
            );

            return response()->noContent();
        });
    }

    private function matchQuery(int $programId, ?int $levelId, int $termId, string $bucket)
    {
        return UnitLimit::query()
            ->where('program_id', $programId)
            ->where('bucket', $bucket)
            ->where('academic_term_id', $termId)
            ->when(
                $levelId === null,
                fn ($query) => $query->whereNull('academic_level_id'),
                fn ($query) => $query->where('academic_level_id', $levelId),
            );
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
