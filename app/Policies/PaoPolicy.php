<?php

namespace App\Policies;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Pao;
use App\Models\User;

class PaoPolicy
{
    use AuthorizesPlanningScope;

    public function viewAny(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->hasDelegatedPermission('planning_read')
            || $user->hasDelegatedPermission('planning_write');
    }

    public function view(User $user, Pao $pao): bool
    {
        return $this->canReadPao($user, (int) $pao->id, (int) $pao->direction_id);
    }

    public function create(User $user, ?int $directionId = null): bool
    {
        if ($directionId === null) {
            return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
        }

        return $this->canManagePao($user, $directionId);
    }

    public function update(User $user, Pao $pao): bool
    {
        return $this->canManagePao($user, (int) $pao->direction_id);
    }

    public function delete(User $user, Pao $pao): bool
    {
        return $this->canManagePao($user, (int) $pao->direction_id);
    }
}
