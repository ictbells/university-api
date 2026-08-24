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
     * @return array{department: OfficeDepartment, unit: ?OfficeUnit}|null
     */
    public function ownerForNavKey(string $navKey): ?array
    {
        $links = OfficeNavLink::query()
            ->with('linkable')
            ->where('nav_key', $navKey)
            ->get();

        $department = null;
        $unit = null;

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
        }

        if (! $department) {
            return null;
        }

        return ['department' => $department, 'unit' => $unit];
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
}
