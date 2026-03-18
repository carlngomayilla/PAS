<?php

namespace App\Policies\Concerns;

use App\Models\Pao;
use App\Models\User;

trait HandlesPaoAuthorization
{
    protected function canReadDirection(User $user, ?int $directionId): bool
    {
        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
            return (int) $user->direction_id === $directionId;
        }

        return false;
    }

    protected function canReadPao(User $user, ?int $paoId, ?int $directionId): bool
    {
        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        if ($paoId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return $directionId !== null && (int) $user->direction_id === (int) $directionId;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            return Pao::query()
                ->whereKey((int) $paoId)
                ->whereHas('ptas', fn ($q) => $q->where('service_id', (int) $user->service_id))
                ->exists();
        }

        return false;
    }

    protected function canWriteDirection(User $user, ?int $directionId): bool
    {
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return (int) $user->direction_id === $directionId;
        }

        return false;
    }

    protected function canCreateAtLeastOneDirection(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
    }
}
