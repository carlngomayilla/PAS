<?php

namespace App\Policies;

use App\Models\Pao;
use App\Models\PaoAxe;
use App\Models\User;
use App\Policies\Concerns\HandlesPaoAuthorization;

class PaoAxePolicy
{
    use HandlesPaoAuthorization;

    public function viewAny(User $user): bool
    {
        $directionId = $user->direction_id !== null ? (int) $user->direction_id : null;

        return $this->canReadDirection($user, $directionId) || $user->hasGlobalReadAccess();
    }

    public function view(User $user, PaoAxe $paoAxe): bool
    {
        $paoId = $paoAxe->pao_id !== null ? (int) $paoAxe->pao_id : null;
        $directionId = $paoAxe->pao()->value('direction_id');

        return $this->canReadPao($user, $paoId, $directionId !== null ? (int) $directionId : null);
    }

    public function create(User $user): bool
    {
        return $this->canCreateAtLeastOneDirection($user);
    }

    public function createForPao(User $user, Pao $pao): bool
    {
        return $this->canWriteDirection($user, (int) $pao->direction_id);
    }

    public function update(User $user, PaoAxe $paoAxe): bool
    {
        $directionId = $paoAxe->pao()->value('direction_id');

        return $this->canWriteDirection($user, $directionId !== null ? (int) $directionId : null);
    }

    public function delete(User $user, PaoAxe $paoAxe): bool
    {
        return $this->update($user, $paoAxe);
    }

    public function restore(User $user, PaoAxe $paoAxe): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaoAxe $paoAxe): bool
    {
        return false;
    }
}
