<?php

namespace App\Policies;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Pas;
use App\Models\User;

class PasPolicy
{
    use AuthorizesPlanningScope;

    public function viewAny(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->hasDelegatedPermission('planning_read')
            || $user->hasDelegatedPermission('planning_write');
    }

    public function view(User $user, Pas $pas): bool
    {
        return $this->canReadPas($user, (int) $pas->id);
    }

    public function create(User $user): bool
    {
        return $this->canWriteStrategicPlanning($user);
    }

    public function update(User $user, Pas $pas): bool
    {
        return $this->canWriteStrategicPlanning($user);
    }

    public function delete(User $user, Pas $pas): bool
    {
        return $this->canWriteStrategicPlanning($user);
    }
}
