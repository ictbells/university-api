<?php

namespace App\Services;

use App\Models\OfficeDepartment;
use App\Models\OfficeNavLink;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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
    public function ownerForNavKey(string $navKey): ?array
    {
        $links = OfficeNavLink::query()
            ->with('linkable')
            ->where('nav_key', $navKey)
            ->get();

        $department = null;
        $unit = null;
        $requireCreate = false;
        $requireUpdate = false;
        $requireDelete = false;
        $chains = [];

        foreach ($links as $link) {
            $resolved = $this->departmentAndUnit($link->linkable);
            if (! ($resolved['department'] ?? null)) {
                continue;
            }
            if ($department && (int) $department->id !== (int) $resolved['department']->id) {
                throw ValidationException::withMessages([
                    'nav_keys' => ["The module \"{$navKey}\" is linked to more than one office department."],
                ]);
            }
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

    public function assertKeysUniqueToDepartment(Model $model, array $keys): void
    {
        $tree = $this->departmentAndUnit($model);
        $departmentId = $tree['department']->id ?? null;
        if (! $departmentId) {
            return;
        }

        foreach ($keys as $key) {
            $links = OfficeNavLink::query()
                ->with('linkable')
                ->where('nav_key', $key)
                ->get();

            foreach ($links as $link) {
                if ($link->linkable_type === $model->getMorphClass() && (int) $link->linkable_id === (int) $model->getKey()) {
                    continue;
                }
                $owner = $this->departmentAndUnit($link->linkable);
                $ownerId = $owner['department']->id ?? null;
                if ($ownerId && (int) $ownerId !== (int) $departmentId) {
                    throw ValidationException::withMessages([
                        'nav_keys' => ["\"{$key}\" is already linked to another office department."],
                    ]);
                }
            }
        }
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
