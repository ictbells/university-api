<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Program;
use App\Models\ProgrammeFee;
use App\Services\AuditWriter;
use App\Support\FeeSchedule;
use App\Support\ProgrammeFeeResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgrammeFeeController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

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
        $levelCode = (string) $request->input('level', $request->input('level_code', ''));

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

        $rows = $query->orderBy('name')->get()->map(function (Program $program) use ($levelCode) {
            $lines = $program->programmeFees->filter(fn (ProgrammeFee $fee) => $fee->feeItem?->is_active !== false);
            if ($levelCode !== '' && $levelCode !== 'all') {
                $lines = $lines->filter(fn (ProgrammeFee $fee) => $fee->level_code === 'all' || $fee->level_code === $levelCode);
            }
            $total = ProgrammeFeeResolver::scheduleFullAmount($lines);

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
                'levels' => AcademicLevel::query()
                    ->where('is_active', true)
                    ->orderBy('study_level')
                    ->orderBy('sort_order')
                    ->get(['code', 'name', 'study_level'])
                    ->map(fn (AcademicLevel $level) => [
                        'value' => (string) ($level->code ?: $level->name),
                        'label' => (string) ($level->name ?: $level->code),
                        'study_level' => $level->study_level,
                    ])
                    ->filter(fn (array $row) => $row['value'] !== '')
                    ->values(),
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
            'total_amount' => ProgrammeFeeResolver::scheduleFullAmount($fees),
        ];
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $this->validated($request);

        return $this->officeGate('finance.store_programme_fee', null, $data, 'Create programme fee', function () use ($data) {
            $keys = [
                'program_id' => $data['program_id'],
                'fee_item_id' => $data['fee_item_id'],
                'level_code' => $data['level_code'] ?? 'all',
                'semester' => $data['semester'] ?? 'both',
                'installment_tranche' => $data['installment_tranche'] ?? null,
            ];
            $values = array_diff_key($data, $keys);
            $fee = $this->upsertProgrammeFee($keys, $values);
            $this->syncProgramTuitionCache((int) $fee->program_id);
            $this->audit->record('programme_fee.created', 'Programme fee assigned', 'fees', 'programme_fee', $fee->id, null, $fee);

            return $this->serialize($fee->load(['program', 'feeItem']));
        });
    }

    public function update(Request $request, ProgrammeFee $programmeFee)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $this->validated($request, partial: true);

        return $this->officeGate(
            'finance.update_programme_fee',
            $programmeFee,
            ['programme_fee_id' => $programmeFee->id, ...$data],
            'Update programme fee',
            function () use ($programmeFee, $data) {
                $before = $programmeFee->toArray();
                $programmeFee->update($data);
                $this->syncProgramTuitionCache((int) $programmeFee->program_id);
                $this->audit->record('programme_fee.updated', 'Programme fee updated', 'fees', 'programme_fee', $programmeFee->id, $before, $programmeFee);

                return $this->serialize($programmeFee->fresh(['program', 'feeItem']));
            },
        );
    }

    public function destroy(Request $request, ProgrammeFee $programmeFee)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        return $this->officeGate(
            'finance.destroy_programme_fee',
            $programmeFee,
            ['programme_fee_id' => $programmeFee->id],
            'Delete programme fee',
            function () use ($programmeFee) {
                $before = $programmeFee->toArray();
                $programId = (int) $programmeFee->program_id;
                $programmeFee->delete();
                $this->syncProgramTuitionCache($programId);
                $this->audit->record('programme_fee.deleted', 'Programme fee removed', 'fees', 'programme_fee', $programmeFee->id, $before, null);

                return response()->noContent();
            },
        );
    }

    public function bulkStore(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'level_code' => 'nullable|string|max:20',
            'level_codes' => 'nullable|array',
            'level_codes.*' => 'string|max:20',
            'semester' => ['nullable', Rule::in(FeeSchedule::SEMESTERS)],
            'items' => 'required|array|min:1',
            'items.*.fee_item_id' => 'required|integer|exists:fee_items,id',
            'items.*.amount' => 'nullable|numeric|min:0',
            'items.*.installment_tranche' => ['nullable', 'integer', Rule::in(FeeSchedule::INSTALLMENT_TRANCHES)],
            'items.*.display_order' => 'nullable|integer|min:0',
            'items.*.is_active' => 'nullable|boolean',
        ]);

        return $this->officeGate('finance.bulk_programme_fees', null, $data, 'Bulk save programme fees', function () use ($request, $data) {
            $programId = (int) $data['program_id'];
            $levelCodes = $this->bulkLevelCodes($data);
            $semester = $data['semester'] ?? 'both';
            $created = [];

            DB::transaction(function () use ($data, $programId, $levelCodes, $semester, &$created) {
                foreach ($levelCodes as $levelCode) {
                    foreach ($data['items'] as $index => $item) {
                        $feeItem = FeeItem::query()->findOrFail($item['fee_item_id']);
                        if (! FeeSchedule::isScheduleCategory((string) $feeItem->category)) {
                            abort(422, "Fee “{$feeItem->name}” cannot be assigned to a programme schedule.");
                        }

                        $row = $this->upsertProgrammeFee(
                            [
                                'program_id' => $programId,
                                'fee_item_id' => $feeItem->id,
                                'level_code' => $levelCode,
                                'semester' => $semester,
                                'installment_tranche' => $item['installment_tranche'] ?? null,
                            ],
                            [
                                'amount' => array_key_exists('amount', $item) ? $item['amount'] : null,
                                'display_order' => $item['display_order'] ?? ($index + 1),
                                'is_active' => array_key_exists('is_active', $item) ? (bool) $item['is_active'] : true,
                            ]
                        );
                        $created[] = $row->id;
                    }
                }
            });

            $this->syncProgramTuitionCache($programId);
            $this->audit->record('programme_fee.bulk', 'Programme fees bulk assigned', 'fees', 'program', $programId, null, ['ids' => $created]);

            return $this->byProgram($request, Program::query()->findOrFail($programId));
        });
    }

    public function copySchedule(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'from_program_id' => 'required|integer|exists:programs,id',
            'to_program_ids' => 'required|array|min:1',
            'to_program_ids.*' => 'required|integer|exists:programs,id',
            'replace' => 'sometimes|boolean',
        ]);

        $fromId = (int) $data['from_program_id'];
        $toIds = array_values(array_unique(array_map('intval', $data['to_program_ids'])));
        $toIds = array_values(array_filter($toIds, fn (int $id) => $id !== $fromId));
        if ($toIds === []) {
            abort(422, 'Select at least one other programme.');
        }

        $sourceProgram = Program::query()->with('department')->findOrFail($fromId);
        $sourceFacultyId = $sourceProgram->department?->faculty_id;
        $targets = Program::query()->with('department')->whereIn('id', $toIds)->get();
        if ($targets->count() !== count($toIds)) {
            abort(422, 'One or more destination programmes were not found.');
        }
        if ($sourceFacultyId) {
            $outside = $targets->first(
                fn (Program $program) => (int) ($program->department?->faculty_id ?? 0) !== (int) $sourceFacultyId
            );
            if ($outside) {
                abort(422, 'Copy is limited to programmes in the same college as the source.');
            }
        }

        $sourceLines = ProgrammeFee::query()
            ->where('program_id', $fromId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        if ($sourceLines->isEmpty()) {
            abort(422, 'The source programme has no fee lines to copy.');
        }

        $replace = $request->boolean('replace');

        return $this->officeGate(
            'finance.copy_programme_fees',
            null,
            [...$data, 'to_program_ids' => $toIds, 'replace' => $replace],
            'Copy programme fee schedule',
            function () use ($sourceLines, $fromId, $toIds, $replace) {
                $copied = [];

                DB::transaction(function () use ($sourceLines, $toIds, $replace, &$copied) {
                    foreach ($toIds as $toId) {
                        if ($replace) {
                            ProgrammeFee::query()->where('program_id', $toId)->delete();
                        }
                        foreach ($sourceLines as $index => $line) {
                            $feeItem = FeeItem::query()->find($line->fee_item_id);
                            if (! $feeItem || ! FeeSchedule::isScheduleCategory((string) $feeItem->category)) {
                                continue;
                            }
                            $row = $this->upsertProgrammeFee(
                                [
                                    'program_id' => $toId,
                                    'fee_item_id' => $line->fee_item_id,
                                    'level_code' => $line->level_code ?: 'all',
                                    'semester' => $line->semester ?: 'both',
                                    'installment_tranche' => $line->installment_tranche,
                                ],
                                [
                                    'amount' => $line->amount,
                                    'display_order' => $line->display_order ?? ($index + 1),
                                    'is_active' => (bool) $line->is_active,
                                ]
                            );
                            $copied[] = $row->id;
                        }
                        $this->syncProgramTuitionCache($toId);
                    }
                });

                $this->audit->record('programme_fee.copied', 'Programme fee schedule copied', 'fees', 'program', $fromId, null, [
                    'from_program_id' => $fromId,
                    'to_program_ids' => $toIds,
                    'replace' => $replace,
                    'ids' => $copied,
                ]);

                return [
                    'from_program_id' => $fromId,
                    'to_program_ids' => $toIds,
                    'copied_lines' => $sourceLines->count(),
                    'programmes' => count($toIds),
                ];
            },
        );
    }

    /**
     * Upsert a programme fee row, restoring a soft-deleted match when one exists
     * instead of colliding with the unique index.
     *
     * @param  array<string, mixed>  $keys    Unique-key columns
     * @param  array<string, mixed>  $values  Non-key columns to set/update
     */
    private function upsertProgrammeFee(array $keys, array $values): ProgrammeFee
    {
        $trashed = ProgrammeFee::withTrashed()
            ->where($keys)
            ->whereNotNull('deleted_at')
            ->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update($values);

            return $trashed->fresh();
        }

        try {
            return ProgrammeFee::query()->updateOrCreate($keys, $values);
        } catch (UniqueConstraintViolationException) {
            // A concurrent insert beat us — fall back to an update on the live row.
            $live = ProgrammeFee::query()->where($keys)->firstOrFail();
            $live->update($values);

            return $live->fresh();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function bulkLevelCodes(array $data): array
    {
        $codes = [];
        if (! empty($data['level_codes']) && is_array($data['level_codes'])) {
            $codes = $data['level_codes'];
        } elseif (! empty($data['level_code'])) {
            $codes = [$data['level_code']];
        }

        $normalized = [];
        foreach ($codes as $code) {
            $trimmed = trim((string) $code);
            $value = strcasecmp($trimmed, 'all') === 0 ? 'all' : $trimmed;
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        $values = array_values($normalized);
        if ($values === []) {
            return ['all'];
        }

        if (in_array('all', $values, true) && count($values) > 1) {
            return array_values(array_filter($values, fn (string $code) => $code !== 'all'));
        }

        return $values;
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
            'installment_tranche' => ['nullable', 'integer', Rule::in(FeeSchedule::INSTALLMENT_TRANCHES)],
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
            'installment_tranche' => $fee->installment_tranche,
            'effective_installment_tranche' => $fee->effective_installment_tranche,
            'effective_installment_tranche_label' => FeeSchedule::installmentTrancheLabel($fee->effective_installment_tranche),
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
                'installment_tranche' => $fee->feeItem->installment_tranche,
                'installment_tranche_label' => FeeSchedule::installmentTrancheLabel(
                    $fee->feeItem->installment_tranche !== null ? (int) $fee->feeItem->installment_tranche : null
                ),
            ] : null,
        ];
    }
}
