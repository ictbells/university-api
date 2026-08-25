<?php

namespace App\Services;

use App\Models\OfficeDepartment;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\User;
use App\Support\StaffNavCatalog;

class StaffNavResolver
{
    public function __construct(private StaffOfficePlacement $placement) {}

    public function isUnrestricted(User $user): bool
    {
        return $user->roles()->where('slug', 'super-admin')->where('is_active', true)->exists();
    }

    /**
     * @return array{unrestricted: bool, keys: string[]|null}
     */
    public function resolve(User $user): array
    {
        if ($this->isUnrestricted($user)) {
            return ['unrestricted' => true, 'keys' => null];
        }

        $staff = $user->staff;
        if (! $staff) {
            return ['unrestricted' => false, 'keys' => ['home']];
        }

        $staff = $this->placement->clean($staff);

        $keys = match (true) {
            (bool) $staff->office_subunit_id => $this->subunitNavKeys((int) $staff->office_subunit_id),
            (bool) $staff->office_unit_id => $this->unitNavKeys((int) $staff->office_unit_id),
            (bool) $staff->office_department_id => $this->departmentNavKeys((int) $staff->office_department_id),
            default => [],
        };

        if ($keys === []) {
            $keys = ['home'];
        }

        $isHead = OfficeDepartment::query()->where('head_staff_id', $staff->id)->exists()
            || OfficeUnit::query()->where('head_staff_id', $staff->id)->exists();
        if ($isHead) {
            $keys[] = 'home';
            $keys[] = 'approvals';
        }

        return ['unrestricted' => false, 'keys' => array_values(array_unique($keys))];
    }

    /**
     * @return list<string>
     */
    private function departmentNavKeys(int $departmentId): array
    {
        $department = OfficeDepartment::query()
            ->with(['navLinks', 'units.navLinks', 'units.subunits.navLinks'])
            ->find($departmentId);

        if (! $department) {
            return [];
        }

        $keys = $department->navKeys();

        foreach ($department->units as $unit) {
            $keys = [...$keys, ...$unit->navKeys()];
            foreach ($unit->subunits as $subunit) {
                $keys = [...$keys, ...$subunit->navKeys()];
            }
        }

        return $keys;
    }

    /**
     * Unit staff inherit department links; also see links assigned on child subunits.
     *
     * @return list<string>
     */
    private function unitNavKeys(int $unitId): array
    {
        $unit = OfficeUnit::query()
            ->with(['navLinks', 'subunits.navLinks', 'department.navLinks'])
            ->find($unitId);

        if (! $unit) {
            return [];
        }

        $keys = [
            ...($unit->department?->navKeys() ?? []),
            ...$unit->navKeys(),
        ];

        foreach ($unit->subunits as $subunit) {
            $keys = [...$keys, ...$subunit->navKeys()];
        }

        return $keys;
    }

    /**
     * Subunit staff inherit unit and department links.
     *
     * @return list<string>
     */
    private function subunitNavKeys(int $subunitId): array
    {
        $subunit = OfficeSubunit::query()
            ->with(['navLinks', 'unit.navLinks', 'unit.department.navLinks'])
            ->find($subunitId);

        if (! $subunit) {
            return [];
        }

        return [
            ...($subunit->unit?->department?->navKeys() ?? []),
            ...($subunit->unit?->navKeys() ?? []),
            ...$subunit->navKeys(),
        ];
    }

    public function catalog(): array
    {
        return StaffNavCatalog::all();
    }
}
