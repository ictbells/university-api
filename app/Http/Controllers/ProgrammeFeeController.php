<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Program;
use App\Models\ProgrammeFee;
use App\Services\AuditWriter;
use App\Support\FeeSchedule;
use App\Support\ProgrammeFeeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgrammeFeeController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $query = ProgrammeFee::query()->with(['program.department', 'feeItem']);

        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->program_id);
        }
        if ($request->filled('level_code')) {
            $level = (string) $request->level_code;
            $query->where(function ($builder) use ($level) {
                $builder->where('level_code', 'all')->orWhere('level_code', $level);
            });
        }
        if ($request->filled('semester') && $request->semester !== 'both') {
            $semester = (string) $request->semester;
            $query->where(function ($builder) use ($semester) {
                $builder->where('semester', $semester)->orWhere('semester', 'both');
            });
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $rows = $query->orderBy('display_order')->orderBy('id')->get()
            ->map(fn (ProgrammeFee $fee) => $this->serialize($fee));

        $total = null;
        if ($request->filled('program_id')) {
            $total = ProgrammeFeeResolver::totalForProgram(
                (int) $request->program_id,
                $request->filled('level_code') ? (string) $request->level_code : null,
                $request->filled('semester') ? (string) $request->semester : null,
            );
        }

        return [
            'data' => $rows,
            'total_amount' => $total,
        ];
    }

    public function summaries(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $search = trim((string) $request->input('search', ''));
        $facultyId = $request->filled('faculty_id') ? (int) $request->faculty_id : null;
        $departmentId = $request->filled('department_id') ? (int) $request->department_id : null;
        $studyLevel = (string) $request->input('study_level', '');
        $scheduled = (string) $request->input('scheduled', 'all');

        $query = Program::query()->with([
            'department.faculty',
            'programmeFees' => fn ($fees) => $fees->where('is_active', true)->with('feeItem'),
        ]);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        }
        if ($facultyId) {
            $query->whereHas('department', fn ($builder) => $builder->where('faculty_id', $facultyId));
        }
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }
        if (in_array($studyLevel, ['undergraduate', 'postgraduate'], true)) {
            $query->where('study_level', $studyLevel);
        }

        $rows = $query->orderBy('name')->get()->map(function (Program $program) {
            $lines = $program->programmeFees->filter(fn (ProgrammeFee $fee) => $fee->feeItem?->is_active !== false);
            $total = round((float) $lines->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2);

            return [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->code,
                'study_level' => $program->study_level,
                'is_active' => $program->is_active,
                'department' => $program->department?->only(['id', 'name']),
                'faculty' => $program->department?->faculty?->only(['id', 'name']),
                'line_count' => $lines->count(),
                'total_amount' => $total,
            ];
        });

        if ($scheduled === 'yes') {
            $rows = $rows->filter(fn (array $row) => $row['line_count'] > 0);
        } elseif ($scheduled === 'no') {
            $rows = $rows->filter(fn (array $row) => $row['line_count'] === 0);
        }

        $rows = $rows->values();
        $departments = Department::query()
            ->when($facultyId, fn ($builder) => $builder->where('faculty_id', $facultyId))
            ->orderBy('name')
            ->get(['id', 'name', 'faculty_id']);

        return [
            'data' => $rows,
            'meta' => [
                'programmes' => $rows->count(),
                'with_schedule' => $rows->where('line_count', '>', 0)->count(),
                'without_schedule' => $rows->where('line_count', '=', 0)->count(),
            ],
            'filters' => [
                'faculties' => Faculty::query()->orderBy('name')->get(['id', 'name']),
                'departments' => $departments,
            ],
        ];
    }

    public function byProgram(Request $request, Program $program)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $level = $request->filled('level_code') ? (string) $request->level_code : null;
        $semester = $request->filled('semester') ? (string) $request->semester : null;
        $fees = ProgrammeFeeResolver::forProgram($program->id, $level, $semester);

        return [
            'program' => $program->only(['id', 'name', 'code', 'tuition_amount']),
            'data' => $fees->map(fn (ProgrammeFee $fee) => $this->serialize($fee))->values(),
            'total_amount' => round((float) $fees->sum(fn (ProgrammeFee $fee) => $fee->effective_amount), 2),
        ];
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $this->validated($request);
        $fee = ProgrammeFee::query()->create($data);
        $this->syncProgramTuitionCache((int) $fee->program_id);
        $this->audit->record('programme_fee.created', 'Programme fee assigned', 'fees', 'programme_fee', $fee->id, null, $fee);

        return $this->serialize($fee->load(['program', 'feeItem']));
    }

    public function update(Request $request, ProgrammeFee $programmeFee)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $before = $programmeFee->toArray();
        $data = $this->validated($request, partial: true);
        $programmeFee->update($data);
        $this->syncProgramTuitionCache((int) $programmeFee->program_id);
        $this->audit->record('programme_fee.updated', 'Programme fee updated', 'fees', 'programme_fee', $programmeFee->id, $before, $programmeFee);

        return $this->serialize($programmeFee->fresh(['program', 'feeItem']));
    }

    public function destroy(Request $request, ProgrammeFee $programmeFee)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $before = $programmeFee->toArray();
        $programId = (int) $programmeFee->program_id;
        $programmeFee->delete();
        $this->syncProgramTuitionCache($programId);
        $this->audit->record('programme_fee.deleted', 'Programme fee removed', 'fees', 'programme_fee', $programmeFee->id, $before, null);

        return response()->noContent();
    }

    public function bulkStore(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'level_code' => 'nullable|string|max:20',
            'semester' => ['nullable', Rule::in(FeeSchedule::SEMESTERS)],
            'items' => 'required|array|min:1',
            'items.*.fee_item_id' => 'required|integer|exists:fee_items,id',
            'items.*.amount' => 'nullable|numeric|min:0',
            'items.*.display_order' => 'nullable|integer|min:0',
            'items.*.is_active' => 'nullable|boolean',
        ]);

        $programId = (int) $data['program_id'];
        $levelCode = $data['level_code'] ?: 'all';
        $semester = $data['semester'] ?? 'both';
        $created = [];

        DB::transaction(function () use ($data, $programId, $levelCode, $semester, &$created) {
            foreach ($data['items'] as $index => $item) {
                $feeItem = FeeItem::query()->findOrFail($item['fee_item_id']);
                if (! FeeSchedule::isScheduleCategory((string) $feeItem->category)) {
                    abort(422, "Fee “{$feeItem->name}” cannot be assigned to a programme schedule.");
                }

                $row = ProgrammeFee::query()->updateOrCreate(
                    [
                        'program_id' => $programId,
                        'fee_item_id' => $feeItem->id,
                        'level_code' => $levelCode,
                        'semester' => $semester,
                    ],
                    [
                        'amount' => array_key_exists('amount', $item) ? $item['amount'] : null,
                        'display_order' => $item['display_order'] ?? ($index + 1),
                        'is_active' => array_key_exists('is_active', $item) ? (bool) $item['is_active'] : true,
                    ]
                );
                $created[] = $row->id;
            }
        });

        $this->syncProgramTuitionCache($programId);
        $this->audit->record('programme_fee.bulk', 'Programme fees bulk assigned', 'fees', 'program', $programId, null, ['ids' => $created]);

        return $this->byProgram($request, Program::query()->findOrFail($programId));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'program_id' => [$required, 'integer', 'exists:programs,id'],
            'fee_item_id' => [$required, 'integer', 'exists:fee_items,id'],
            'amount' => 'nullable|numeric|min:0',
            'level_code' => 'nullable|string|max:20',
            'semester' => ['nullable', Rule::in(FeeSchedule::SEMESTERS)],
            'is_active' => 'boolean',
            'display_order' => 'nullable|integer|min:0',
        ]);

        if (isset($data['fee_item_id'])) {
            $category = FeeItem::query()->whereKey($data['fee_item_id'])->value('category');
            if (! FeeSchedule::isScheduleCategory((string) $category)) {
                abort(422, 'Only school-fee catalog items (tuition, library, medical, etc.) can be assigned to programmes.');
            }
        }

        if (array_key_exists('level_code', $data)) {
            $data['level_code'] = $data['level_code'] ?: 'all';
        } elseif (! $partial) {
            $data['level_code'] = 'all';
        }

        if (array_key_exists('semester', $data)) {
            $data['semester'] = $data['semester'] ?: 'both';
        } elseif (! $partial) {
            $data['semester'] = 'both';
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        } elseif (! $partial) {
            $data['is_active'] = true;
        }

        return $data;
    }

    private function syncProgramTuitionCache(int $programId): void
    {
        $total = ProgrammeFeeResolver::totalForProgram($programId);
        Program::query()->whereKey($programId)->update([
            'tuition_amount' => $total > 0 ? $total : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ProgrammeFee $fee): array
    {
        return [
            'id' => $fee->id,
            'program_id' => $fee->program_id,
            'fee_item_id' => $fee->fee_item_id,
            'amount' => $fee->amount,
            'effective_amount' => $fee->effective_amount,
            'level_code' => $fee->level_code,
            'semester' => $fee->semester,
            'is_active' => $fee->is_active,
            'display_order' => $fee->display_order,
            'program' => $fee->program ? $fee->program->only(['id', 'name', 'code']) : null,
            'fee_item' => $fee->feeItem ? [
                'id' => $fee->feeItem->id,
                'name' => $fee->feeItem->name,
                'category' => $fee->feeItem->category,
                'amount' => $fee->feeItem->amount,
            ] : null,
        ];
    }
}
