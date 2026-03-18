<?php

namespace App\Policies;

use App\Models\PaoAxe;
use App\Models\PaoObjectifStrategique;
use App\Models\User;
use App\Policies\Concerns\HandlesPaoAuthorization;

class PaoObjectifStrategiquePolicy
{
    use HandlesPaoAuthorization;

    public function viewAny(User $user): bool
    {
        $directionId = $user->direction_id !== null ? (int) $user->direction_id : null;

        return $this->canReadDirection($user, $directionId) || $user->hasGlobalReadAccess();
    }

    public function view(User $user, PaoObjectifStrategique $paoObjectifStrategique): bool
    {
        $row = $paoObjectifStrategique
            ->paoAxe()
            ->join('paos', 'paos.id', '=', 'pao_axes.pao_id')
            ->first(['paos.id as pao_id', 'paos.direction_id as direction_id']);

        $paoId = $row?->pao_id !== null ? (int) $row->pao_id : null;
        $directionId = $row?->direction_id !== null ? (int) $row->direction_id : null;

        return $this->canReadPao($user, $paoId, $directionId);
    }

    public function create(User $user): bool
    {
        return $this->canCreateAtLeastOneDirection($user);
    }

    public function createForAxe(User $user, PaoAxe $paoAxe): bool
    {
        $directionId = $paoAxe->pao()->value('direction_id');

        return $this->canWriteDirection($user, $directionId !== null ? (int) $directionId : null);
    }

    public function update(User $user, PaoObjectifStrategique $paoObjectifStrategique): bool
    {
        $directionId = $paoObjectifStrategique
            ->paoAxe()
            ->join('paos', 'paos.id', '=', 'pao_axes.pao_id')
            ->value('paos.direction_id');

        return $this->canWriteDirection($user, $directionId !== null ? (int) $directionId : null);
    }

    public function delete(User $user, PaoObjectifStrategique $paoObjectifStrategique): bool
    {
        return $this->update($user, $paoObjectifStrategique);
    }

    public function restore(User $user, PaoObjectifStrategique $paoObjectifStrategique): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaoObjectifStrategique $paoObjectifStrategique): bool
    {
        return false;
    }
}
