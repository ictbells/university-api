<?php

namespace App\Services;

use App\Models\User;
use App\Support\AcademicResourceCatalog;

class AcademicResourceAccess
{
    public function __construct(private StaffNavResolver $nav) {}

    public function accessState(User $user, string $resourceKey): array
    {
        $permission = AcademicResourceCatalog::permission($resourceKey);
        if (! $permission) {
            return [
                'can_access' => false,
                'level' => 'none',
                'reason' => 'missing_both',
            ];
        }

        $hasPermission = $this->hasResourcePermission($user, $resourceKey, $permission);
        $unrestricted = $this->nav->isUnrestricted($user);
        $inPortal = $unrestricted || in_array(
            $resourceKey,
            $this->nav->resolve($user)['keys'] ?? [],
            true,
        );

        if ($hasPermission && $inPortal) {
            return [
                'can_access' => true,
                'level' => $unrestricted ? 'full' : 'limited',
                'reason' => 'ok',
            ];
        }

        $reason = 'missing_both';
        if ($hasPermission && ! $inPortal) {
            $reason = 'missing_portal_link';
        } elseif (! $hasPermission && $inPortal) {
            $reason = 'missing_permission';
        }

        return [
            'can_access' => false,
            'level' => 'none',
            'reason' => $reason,
        ];
    }

    public function canAccess(User $user, string $resourceKey): bool
    {
        return $this->accessState($user, $resourceKey)['can_access'];
    }

    private function hasResourcePermission(User $user, string $resourceKey, string $permission): bool
    {
        if ($user->hasPermission($permission)) {
            return true;
        }

        if (in_array($resourceKey, ['programmes', 'courses'], true)
            && $user->hasPermission('academic.catalog.manage')) {
            return true;
        }

        if (in_array($resourceKey, ['campuses', 'colleges', 'departments', 'sessions', 'levels', 'intakes', 'olevel'], true)
            && $user->hasPermission('institution.manage')) {
            return true;
        }

        return false;
    }

    public function canAccessAny(User $user, array $resourceKeys): bool
    {
        foreach ($resourceKeys as $key) {
            if ($this->canAccess($user, $key)) {
                return true;
            }
        }

        return false;
    }
}
