<?php

namespace App\Services;

use App\Models\OfficeDepartment;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Collection;

class StaffOfficePlacement
{
    public function enrich(User $user): void
    {
        if (! $user->staff) {
            return;
        }

        $staff = $this->clean($user->staff);
        $staff->setAttribute('office_placement', $this->label($staff));
        $staff->setAttribute('office_placement_stale', $this->isStale($staff));
        $staff->setAttribute('is_office_hod', OfficeDepartment::query()->where('head_staff_id', $staff->id)->exists());
        $staff->setAttribute('is_office_unit_head', OfficeUnit::query()->where('head_staff_id', $staff->id)->exists());
        $user->setRelation('staff', $staff);
    }

    /**
     * Batch enrichment for staff user lists (avoids per-row office lookups).
     *
     * @param  iterable<int, User>  $users
     */
    public function enrichMany(iterable $users): void
    {
        $users = $users instanceof Collection ? $users : collect($users);
        $staffModels = $users->map(fn (User $user) => $user->staff)->filter();
        if ($staffModels->isEmpty()) {
            return;
        }

        $staffIds = $staffModels->pluck('id')->map(fn ($id) => (int) $id)->all();
        $hodSet = array_flip(
            OfficeDepartment::query()->whereIn('head_staff_id', $staffIds)->pluck('head_staff_id')->all()
        );
        $unitHeadSet = array_flip(
            OfficeUnit::query()->whereIn('head_staff_id', $staffIds)->pluck('head_staff_id')->all()
        );

        $subunitIds = $staffModels->pluck('office_subunit_id')->filter()->unique()->values()->all();
        $unitIds = $staffModels->pluck('office_unit_id')->filter()->unique()->values()->all();
        $departmentIds = $staffModels->pluck('office_department_id')->filter()->unique()->values()->all();

        $subunits = $subunitIds === []
            ? collect()
            : OfficeSubunit::query()->with('unit.department')->whereIn('id', $subunitIds)->get()->keyBy('id');
        $units = $unitIds === []
            ? collect()
            : OfficeUnit::query()->with('department')->whereIn('id', $unitIds)->get()->keyBy('id');
        $departments = $departmentIds === []
            ? collect()
            : OfficeDepartment::query()->whereIn('id', $departmentIds)->get()->keyBy('id');

        foreach ($users as $user) {
            $staff = $user->staff;
            if (! $staff) {
                continue;
            }

            $label = null;
            if ($staff->office_subunit_id && ($sub = $subunits->get($staff->office_subunit_id))) {
                $label = sprintf(
                    '%s › %s › %s',
                    $sub->unit?->department?->name ?? '—',
                    $sub->unit?->name ?? '—',
                    $sub->name,
                );
            } elseif ($staff->office_unit_id && ($unit = $units->get($staff->office_unit_id))) {
                $label = sprintf('%s › %s', $unit->department?->name ?? '—', $unit->name);
            } elseif ($staff->office_department_id && ($dept = $departments->get($staff->office_department_id))) {
                $label = $dept->name;
            }

            $hasPlacementIds = (bool) ($staff->office_department_id || $staff->office_unit_id || $staff->office_subunit_id);
            $staff->setAttribute('office_placement', $label);
            $staff->setAttribute('office_placement_stale', $hasPlacementIds && $label === null);
            $staff->setAttribute('is_office_hod', isset($hodSet[$staff->id]));
            $staff->setAttribute('is_office_unit_head', isset($unitHeadSet[$staff->id]));
            $user->setRelation('staff', $staff);
        }
    }

    public function clean(Staff $staff): Staff
    {
        $dirty = false;

        if ($staff->office_subunit_id && ! OfficeSubunit::query()->whereKey($staff->office_subunit_id)->exists()) {
            $staff->office_subunit_id = null;
            $dirty = true;
        }

        if ($staff->office_unit_id && ! OfficeUnit::query()->whereKey($staff->office_unit_id)->exists()) {
            $staff->office_unit_id = null;
            $dirty = true;
        }

        if ($staff->office_department_id && ! OfficeDepartment::query()->whereKey($staff->office_department_id)->exists()) {
            $staff->office_department_id = null;
            $dirty = true;
        }

        if ($staff->office_subunit_id) {
            $subunit = OfficeSubunit::query()->with('unit')->find($staff->office_subunit_id);
            if (! $subunit
                || ($staff->office_unit_id && (int) $subunit->office_unit_id !== (int) $staff->office_unit_id)
                || ($staff->office_department_id && (int) $subunit->unit?->office_department_id !== (int) $staff->office_department_id)) {
                $staff->office_subunit_id = null;
                $dirty = true;
            }
        }

        if ($staff->office_unit_id) {
            $unit = OfficeUnit::query()->find($staff->office_unit_id);
            if (! $unit || ($staff->office_department_id && (int) $unit->office_department_id !== (int) $staff->office_department_id)) {
                $staff->office_unit_id = null;
                $staff->office_subunit_id = null;
                $dirty = true;
            }
        }

        if ($dirty) {
            $staff->save();
        }

        return $staff->fresh();
    }

    public function label(?Staff $staff): ?string
    {
        if (! $staff) {
            return null;
        }

        if ($staff->office_subunit_id) {
            $sub = OfficeSubunit::query()->with('unit.department')->find($staff->office_subunit_id);
            if ($sub) {
                return sprintf('%s › %s › %s', $sub->unit?->department?->name ?? '—', $sub->unit?->name ?? '—', $sub->name);
            }
        }

        if ($staff->office_unit_id) {
            $unit = OfficeUnit::query()->with('department')->find($staff->office_unit_id);
            if ($unit) {
                return sprintf('%s › %s', $unit->department?->name ?? '—', $unit->name);
            }
        }

        if ($staff->office_department_id) {
            $dept = OfficeDepartment::query()->find($staff->office_department_id);

            return $dept?->name;
        }

        return null;
    }

    public function isStale(?Staff $staff): bool
    {
        if (! $staff) {
            return false;
        }

        if (! $staff->office_department_id && ! $staff->office_unit_id && ! $staff->office_subunit_id) {
            return false;
        }

        return $this->label($staff) === null;
    }
}
