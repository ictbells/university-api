<?php

namespace App\Services;

use App\Models\OfficeDepartment;
use App\Models\OfficeNavLink;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class OfficeNavOwnerResolver
{
    /**
     * @return array{
     *   department: OfficeDepartment,
     *   unit: ?OfficeUnit,
     *   require_create: bool,
     *   require_update: bool,
     *   require_delete: bool,
     *   approval_chain: string
     * }|null
     */
    public function ownerForNavKey(string $navKey, ?User $actor = null): ?array
    {
        if ($actor?->staff) {
            $fromActor = $this->ownerFromStaff($actor->staff, $navKey);
            if ($fromActor) {
                return $fromActor;
            }
        }

        return $this->ownerFromLinks(
            OfficeNavLink::query()->with('linkable')->where('nav_key', $navKey)->get()
        );
    }

    /**
     * Portal links may be assigned to more than one office department.
     * Approval still resolves from the acting staff member's office tree.
     *
     * @param  list<string>  $keys
     */
    public function assertKeysUniqueToDepartment(Model $model, array $keys): void
    {
        // Intentionally empty: the same sidebar module can appear in several offices.
    }

    /**
     * @return array{department: ?OfficeDepartment, unit: ?OfficeUnit}
     */
    public function departmentAndUnit(?Model $model): array
    {
        if ($model instanceof OfficeDepartment) {
            return ['department' => $model, 'unit' => null];
        }
        if ($model instanceof OfficeUnit) {
            return ['department' => $model->department ?? $model->department()->first(), 'unit' => $model];
        }
        if ($model instanceof OfficeSubunit) {
            $unit = $model->unit ?? $model->unit()->first();

            return [
                'department' => $unit?->department ?? $unit?->department()->first(),
                'unit' => $unit,
            ];
        }

        return ['department' => null, 'unit' => null];
    }

    /**
     * @return array{
     *   department: OfficeDepartment,
     *   unit: ?OfficeUnit,
     *   require_create: bool,
     *   require_update: bool,
     *   require_delete: bool,
     *   approval_chain: string
     * }|null
     */
    private function ownerFromStaff(Staff $staff, string $navKey): ?array
    {
        $links = collect();

        if ($staff->office_subunit_id) {
            $subunit = OfficeSubunit::query()
                ->with(['navLinks', 'unit.navLinks', 'unit.department.navLinks'])
                ->find($staff->office_subunit_id);
            if ($subunit) {
                $links = $links
                    ->concat($subunit->navLinks)
                    ->concat($subunit->unit?->navLinks ?? [])
                    ->concat($subunit->unit?->department?->navLinks ?? []);
            }
        } elseif ($staff->office_unit_id) {
            $unit = OfficeUnit::query()
                ->with(['navLinks', 'department.navLinks'])
                ->find($staff->office_unit_id);
            if ($unit) {
                $links = $links
                    ->concat($unit->navLinks)
                    ->concat($unit->department?->navLinks ?? []);
            }
        } elseif ($staff->office_department_id) {
            $department = OfficeDepartment::query()->with('navLinks')->find($staff->office_department_id);
            if ($department) {
                $links = $links->concat($department->navLinks);
            }
        }

        return $this->ownerFromLinks($links->where('nav_key', $navKey)->values());
    }

    /**
     * @param  Collection<int, OfficeNavLink>  $links
     * @return array{
     *   department: OfficeDepartment,
     *   unit: ?OfficeUnit,
     *   require_create: bool,
     *   require_update: bool,
     *   require_delete: bool,
     *   approval_chain: string
     * }|null
     */
    private function ownerFromLinks(Collection $links): ?array
    {
        $byDepartment = [];
        foreach ($links as $link) {
            $resolved = $this->departmentAndUnit($link->linkable);
            $department = $resolved['department'] ?? null;
            if (! $department) {
                continue;
            }
            $byDepartment[(int) $department->id][] = [$link, $resolved];
        }

        // Shared modules have no single global owner. Approval uses the actor's office tree.
        if (count($byDepartment) !== 1) {
            return null;
        }

        $department = null;
        $unit = null;
        $requireCreate = false;
        $requireUpdate = false;
        $requireDelete = false;
        $chains = [];

        foreach (reset($byDepartment) as [$link, $resolved]) {
            $department = $resolved['department'];
            if ($resolved['unit'] && ! $unit) {
                $unit = $resolved['unit'];
            }
            $requireCreate = $requireCreate || (bool) $link->require_create;
            $requireUpdate = $requireUpdate || (bool) $link->require_update;
            $requireDelete = $requireDelete || (bool) $link->require_delete;
            $chains[] = in_array($link->approval_chain, OfficeNavLink::CHAINS, true)
                ? $link->approval_chain
                : OfficeNavLink::CHAIN_BOTH;
        }

        if (! $department) {
            return null;
        }

        return [
            'department' => $department,
            'unit' => $unit,
            'require_create' => $requireCreate,
            'require_update' => $requireUpdate,
            'require_delete' => $requireDelete,
            'approval_chain' => $this->mergeChains($chains),
        ];
    }

    /**
     * @param  list<string>  $chains
     */
    private function mergeChains(array $chains): string
    {
        $unique = array_values(array_unique($chains));
        if ($unique === []) {
            return OfficeNavLink::CHAIN_BOTH;
        }
        if (in_array(OfficeNavLink::CHAIN_BOTH, $unique, true)) {
            return OfficeNavLink::CHAIN_BOTH;
        }
        if (
            in_array(OfficeNavLink::CHAIN_UNIT_HEAD, $unique, true)
            && in_array(OfficeNavLink::CHAIN_DEPARTMENT_HEAD, $unique, true)
        ) {
            return OfficeNavLink::CHAIN_BOTH;
        }

        return $unique[0];
    }
}
