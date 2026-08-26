<?php

namespace App\Http\Controllers;

use App\Models\OfficeDepartment;
use App\Models\OfficeNavLink;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\Staff;
use App\Services\AuditWriter;
use App\Services\OfficeNavOwnerResolver;
use App\Support\StaffNavCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeStructureController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private AuditWriter $audit,
        private OfficeNavOwnerResolver $navOwners,
    ) {}

    public function index()
    {
        return OfficeDepartment::query()
            ->with(['units.subunits.navLinks', 'units.navLinks', 'units.headStaff.user', 'navLinks', 'headStaff.user'])
            ->orderBy('name')
            ->get()
            ->map(fn (OfficeDepartment $dept) => $this->formatDepartment($dept));
    }

    public function navCatalog()
    {
        return collect(StaffNavCatalog::all())
            ->map(fn (array $item) => [
                ...$item,
                'has_approval_actions' => true,
            ])
            ->values()
            ->all();
    }

    public function syncDepartmentNavLinks(Request $request, OfficeDepartment $officeDepartment)
    {
        return $this->syncNavLinks($request, $officeDepartment, 'office.department.nav_links', 'office_department', $officeDepartment->id);
    }

    public function syncUnitNavLinks(Request $request, OfficeUnit $officeUnit)
    {
        return $this->syncNavLinks($request, $officeUnit, 'office.unit.nav_links', 'office_unit', $officeUnit->id);
    }

    public function syncSubunitNavLinks(Request $request, OfficeSubunit $officeSubunit)
    {
        return $this->syncNavLinks($request, $officeSubunit, 'office.subunit.nav_links', 'office_subunit', $officeSubunit->id);
    }

    private function syncNavLinks(Request $request, $model, string $action, string $entityType, int $entityId)
    {
        $data = $request->validate([
            'nav_keys' => 'array',
            'nav_keys.*' => 'string',
            'nav_links' => 'array',
            'nav_links.*.key' => 'required_with:nav_links|string',
            'nav_links.*.require_create' => 'boolean',
            'nav_links.*.require_update' => 'boolean',
            'nav_links.*.require_delete' => 'boolean',
            'nav_links.*.approval_chain' => ['nullable', 'string', Rule::in(OfficeNavLink::CHAINS)],
        ]);

        if (! empty($data['nav_links'])) {
            $links = collect($data['nav_links'])
                ->filter(fn ($row) => StaffNavCatalog::isValidKey($row['key'] ?? ''))
                ->map(fn ($row) => [
                    'key' => $row['key'],
                    'require_create' => array_key_exists('require_create', $row) ? (bool) $row['require_create'] : true,
                    'require_update' => array_key_exists('require_update', $row) ? (bool) $row['require_update'] : true,
                    'require_delete' => array_key_exists('require_delete', $row) ? (bool) $row['require_delete'] : true,
                    'approval_chain' => $row['approval_chain'] ?? OfficeNavLink::CHAIN_BOTH,
                ])
                ->unique('key')
                ->values()
                ->all();
        } else {
            $links = collect($data['nav_keys'] ?? [])
                ->filter(fn ($key) => StaffNavCatalog::isValidKey($key))
                ->unique()
                ->values()
                ->all();
        }

        $keys = collect($links)
            ->map(fn ($row) => is_string($row) ? $row : $row['key'])
            ->values()
            ->all();

        $this->navOwners->assertKeysUniqueToDepartment($model, $keys);

        $actionKey = match (true) {
            $model instanceof OfficeDepartment => 'office.sync_department_nav',
            $model instanceof OfficeUnit => 'office.sync_unit_nav',
            default => 'office.sync_subunit_nav',
        };
        $subjectKey = \Illuminate\Support\Str::snake(class_basename($model)).'_id';

        return $this->officeGate(
            $actionKey,
            $model,
            [$subjectKey => $model->id, ...$data],
            'Update office navigation links',
            function () use ($model, $action, $entityType, $entityId, $links, $keys) {
                $before = $model->navLinkConfigs();
                $model->syncNavLinks($links);
                $after = $model->navLinkConfigs();
                $this->audit->record($action, 'Office navigation links updated', 'institution', $entityType, $entityId, $before, $after);

                return [
                    'nav_keys' => $keys,
                    'nav_links' => $after,
                ];
            },
        );
    }

    private function formatDepartment(OfficeDepartment $dept): array
    {
        $departmentKeys = $dept->navKeys();

        return [
            ...$dept->toArray(),
            'nav_keys' => $departmentKeys,
            'nav_links' => $dept->navLinkConfigs(),
            'inherited_nav_keys' => [],
            'head_staff' => $this->formatHeadStaff($dept->headStaff),
            'needs_hod' => $departmentKeys !== [] && ! $dept->head_staff_id,
            'units' => $dept->units->map(function (OfficeUnit $unit) use ($departmentKeys) {
                $unitKeys = $unit->navKeys();
                $unitInherited = $departmentKeys;

                return [
                    ...$unit->toArray(),
                    'nav_keys' => $unitKeys,
                    'nav_links' => $unit->navLinkConfigs(),
                    'inherited_nav_keys' => $unitInherited,
                    'head_staff' => $this->formatHeadStaff($unit->headStaff),
                    'needs_unit_head' => $unit->subunits->isNotEmpty() && ! $unit->head_staff_id,
                    'subunits' => $unit->subunits->map(function (OfficeSubunit $sub) use ($departmentKeys, $unitKeys) {
                        return [
                            ...$sub->toArray(),
                            'nav_keys' => $sub->navKeys(),
                            'nav_links' => $sub->navLinkConfigs(),
                            'inherited_nav_keys' => array_values(array_unique([...$departmentKeys, ...$unitKeys])),
                        ];
                    })->values(),
                ];
            })->values(),
        ];
    }

    public function storeDepartment(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:office_departments,code',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'head_staff_id' => ['nullable', 'integer', 'exists:staff,id', Rule::unique('office_departments', 'head_staff_id')],
        ]);
        if (! empty($data['head_staff_id'])) {
            $this->assertDepartmentHead((int) $data['head_staff_id']);
        }

        return $this->officeGate('office.store_department', null, $data, 'Create office department '.$data['name'], function () use ($data) {
            $department = OfficeDepartment::query()->create($data);
            $this->audit->record('office.department.created', 'Office department created', 'institution', 'office_department', $department->id, null, $department);

            return $this->formatDepartment($department->load(['units.subunits', 'headStaff.user']));
        });
    }

    public function storeUnit(Request $request)
    {
        $data = $request->validate([
            'office_department_id' => 'required|exists:office_departments,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'head_staff_id' => ['nullable', 'integer', 'exists:staff,id', Rule::unique('office_units', 'head_staff_id')],
        ]);
        if (! empty($data['head_staff_id'])) {
            $this->assertUnitHead((int) $data['head_staff_id'], (int) $data['office_department_id']);
        }

        return $this->officeGate('office.store_unit', null, $data, 'Create office unit '.$data['name'], function () use ($data) {
            $unit = OfficeUnit::query()->create($data);
            $this->audit->record('office.unit.created', 'Office unit created', 'institution', 'office_unit', $unit->id, null, $unit);

            return $unit->load(['subunits', 'headStaff.user']);
        });
    }

    public function storeSubunit(Request $request)
    {
        $data = $request->validate([
            'office_unit_id' => 'required|exists:office_units,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate('office.store_subunit', null, $data, 'Create office subunit '.$data['name'], function () use ($data) {
            $subunit = OfficeSubunit::query()->create($data);
            $this->audit->record('office.subunit.created', 'Office subunit created', 'institution', 'office_subunit', $subunit->id, null, $subunit);

            return $subunit;
        });
    }

    public function updateDepartment(Request $request, OfficeDepartment $officeDepartment)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['nullable', 'string', 'max:50', Rule::unique('office_departments', 'code')->ignore($officeDepartment->id)],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'head_staff_id' => ['nullable', 'integer', 'exists:staff,id', Rule::unique('office_departments', 'head_staff_id')->ignore($officeDepartment->id)],
        ]);
        if (array_key_exists('head_staff_id', $data) && $data['head_staff_id']) {
            $this->assertDepartmentHead((int) $data['head_staff_id'], $officeDepartment->id);
        }

        return $this->officeGate(
            'office.update_department',
            $officeDepartment,
            ['office_department_id' => $officeDepartment->id, ...$data],
            'Update office department '.$officeDepartment->name,
            function () use ($officeDepartment, $data) {
                $before = $officeDepartment->toArray();
                $officeDepartment->update($data);
                $this->audit->record('office.department.updated', 'Office department updated', 'institution', 'office_department', $officeDepartment->id, $before, $officeDepartment);

                return $officeDepartment->fresh()->load('units.subunits');
            },
        );
    }

    public function updateUnit(Request $request, OfficeUnit $officeUnit)
    {
        $data = $request->validate([
            'office_department_id' => 'sometimes|required|exists:office_departments,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('office_units', 'code')
                    ->where('office_department_id', $request->input('office_department_id', $officeUnit->office_department_id))
                    ->ignore($officeUnit->id),
            ],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'head_staff_id' => ['nullable', 'integer', 'exists:staff,id', Rule::unique('office_units', 'head_staff_id')->ignore($officeUnit->id)],
        ]);
        if (array_key_exists('head_staff_id', $data) && $data['head_staff_id']) {
            $this->assertUnitHead(
                (int) $data['head_staff_id'],
                (int) ($data['office_department_id'] ?? $officeUnit->office_department_id),
                $officeUnit->id,
            );
        }

        return $this->officeGate(
            'office.update_unit',
            $officeUnit,
            ['office_unit_id' => $officeUnit->id, ...$data],
            'Update office unit '.$officeUnit->name,
            function () use ($officeUnit, $data) {
                $before = $officeUnit->toArray();
                $officeUnit->update($data);
                $this->audit->record('office.unit.updated', 'Office unit updated', 'institution', 'office_unit', $officeUnit->id, $before, $officeUnit);

                return $officeUnit->fresh()->load('subunits');
            },
        );
    }

    public function updateSubunit(Request $request, OfficeSubunit $officeSubunit)
    {
        $data = $request->validate([
            'office_unit_id' => 'sometimes|required|exists:office_units,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('office_subunits', 'code')
                    ->where('office_unit_id', $request->input('office_unit_id', $officeSubunit->office_unit_id))
                    ->ignore($officeSubunit->id),
            ],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return $this->officeGate(
            'office.update_subunit',
            $officeSubunit,
            ['office_subunit_id' => $officeSubunit->id, ...$data],
            'Update office subunit '.$officeSubunit->name,
            function () use ($officeSubunit, $data) {
                $before = $officeSubunit->toArray();
                $officeSubunit->update($data);
                $this->audit->record('office.subunit.updated', 'Office subunit updated', 'institution', 'office_subunit', $officeSubunit->id, $before, $officeSubunit);

                return $officeSubunit->fresh();
            },
        );
    }

    public function destroyDepartment(OfficeDepartment $officeDepartment)
    {
        return $this->officeGate(
            'office.destroy_department',
            $officeDepartment,
            ['office_department_id' => $officeDepartment->id],
            'Delete office department '.$officeDepartment->name,
            function () use ($officeDepartment) {
                $before = $officeDepartment->load('units.subunits');
                $officeDepartment->delete();
                $this->audit->record('office.department.deleted', 'Office department deleted', 'institution', 'office_department', $before->id, $before, null);

                return response()->json(['message' => 'Department deleted.']);
            },
        );
    }

    public function destroyUnit(OfficeUnit $officeUnit)
    {
        return $this->officeGate(
            'office.destroy_unit',
            $officeUnit,
            ['office_unit_id' => $officeUnit->id],
            'Delete office unit '.$officeUnit->name,
            function () use ($officeUnit) {
                $before = $officeUnit->load('subunits');
                $officeUnit->delete();
                $this->audit->record('office.unit.deleted', 'Office unit deleted', 'institution', 'office_unit', $before->id, $before, null);

                return response()->json(['message' => 'Unit deleted.']);
            },
        );
    }

    public function destroySubunit(OfficeSubunit $officeSubunit)
    {
        return $this->officeGate(
            'office.destroy_subunit',
            $officeSubunit,
            ['office_subunit_id' => $officeSubunit->id],
            'Delete office subunit '.$officeSubunit->name,
            function () use ($officeSubunit) {
                $before = $officeSubunit->toArray();
                $officeSubunit->delete();
                $this->audit->record('office.subunit.deleted', 'Office subunit deleted', 'institution', 'office_subunit', $before['id'], $before, null);

                return response()->json(['message' => 'Subunit deleted.']);
            },
        );
    }

    public function staffOptions(Request $request)
    {
        $departmentId = $request->integer('office_department_id') ?: null;
        $unitId = $request->integer('office_unit_id') ?: null;

        $staff = Staff::query()
            ->with('user:id,name,email')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->when($unitId, function ($query) use ($unitId) {
                $query->where(function ($inner) use ($unitId) {
                    $inner->where('office_unit_id', $unitId)
                        ->orWhereHas('officeSubunit', fn ($sub) => $sub->where('office_unit_id', $unitId));
                });
            })
            ->when($departmentId && ! $unitId, function ($query) use ($departmentId) {
                $query->where(function ($inner) use ($departmentId) {
                    $inner->where('office_department_id', $departmentId)
                        ->orWhereHas('officeUnit', fn ($unit) => $unit->where('office_department_id', $departmentId))
                        ->orWhereHas('officeSubunit.unit', fn ($unit) => $unit->where('office_department_id', $departmentId));
                });
            })
            ->orderBy('id')
            ->get()
            ->map(fn (Staff $row) => [
                'id' => $row->id,
                'name' => $row->user?->name,
                'email' => $row->user?->email,
                'staff_number' => $row->staff_number,
                'office_department_id' => $row->office_department_id,
                'office_unit_id' => $row->office_unit_id,
            ]);

        return ['data' => $staff];
    }

    private function formatHeadStaff(?Staff $staff): ?array
    {
        if (! $staff) {
            return null;
        }

        return [
            'id' => $staff->id,
            'name' => $staff->user?->name,
            'staff_number' => $staff->staff_number,
        ];
    }

    private function assertDepartmentHead(int $staffId, ?int $departmentId = null): void
    {
        abort_if(OfficeUnit::query()->where('head_staff_id', $staffId)->exists(), 422, 'That staff member is already a unit head.');
        if ($departmentId) {
            abort_unless(
                (int) $this->departmentIdForStaff($staffId) === (int) $departmentId,
                422,
                'Head of department must belong to this office department.',
            );
        }
    }

    private function assertUnitHead(int $staffId, int $departmentId, ?int $unitId = null): void
    {
        abort_if(OfficeDepartment::query()->where('head_staff_id', $staffId)->exists(), 422, 'That staff member is already a head of department.');
        abort_unless(
            (int) $this->departmentIdForStaff($staffId) === (int) $departmentId,
            422,
            'Unit head must belong to this office department.',
        );
        if ($unitId) {
            abort_unless(
                (int) $this->unitIdForStaff($staffId) === (int) $unitId,
                422,
                'Unit head must be placed in this unit or one of its subunits.',
            );
        }
    }

    private function departmentIdForStaff(int $staffId): ?int
    {
        $staff = Staff::query()->with(['officeUnit', 'officeSubunit.unit'])->findOrFail($staffId);
        if ($staff->office_subunit_id) {
            return $staff->officeSubunit?->unit?->office_department_id;
        }
        if ($staff->office_unit_id) {
            return $staff->officeUnit?->office_department_id;
        }

        return $staff->office_department_id ? (int) $staff->office_department_id : null;
    }

    private function unitIdForStaff(int $staffId): ?int
    {
        $staff = Staff::query()->with('officeSubunit')->findOrFail($staffId);
        if ($staff->office_subunit_id) {
            return $staff->officeSubunit?->office_unit_id;
        }

        return $staff->office_unit_id ? (int) $staff->office_unit_id : null;
    }
}
