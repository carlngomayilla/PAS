<?php

namespace App\Policies;

use App\Models\PaoObjectifOperationnel;
use App\Models\PaoObjectifStrategique;
use App\Models\User;
use App\Policies\Concerns\HandlesPaoAuthorization;

class PaoObjectifOperationnelPolicy
{
    use HandlesPaoAuthorization;

    public function viewAny(User $user): bool
    {
        $directionId = $user->direction_id !== null ? (int) $user->direction_id : null;

        return $this->canReadDirection($user, $directionId) || $user->hasGlobalReadAccess();
    }

    public function view(User $user, PaoObjectifOperationnel $paoObjectifOperationnel): bool
    {
        $paoScope = $this->resolvePaoScopeForOperationnel($paoObjectifOperationnel);
        $paoId = $paoScope['pao_id'];
        $directionId = $paoScope['direction_id'];

        return $this->canReadPao($user, $paoId, $directionId);
    }

    public function create(User $user): bool
    {
        return $this->canCreateAtLeastOneDirection($user);
    }

    public function createForObjectifStrategique(
        User $user,
        PaoObjectifStrategique $objectifStrategique
    ): bool {
        $directionId = $objectifStrategique
            ->paoAxe()
            ->join('paos', 'paos.id', '=', 'pao_axes.pao_id')
            ->value('paos.direction_id');

        return $this->canWriteDirection($user, $directionId !== null ? (int) $directionId : null);
    }

    public function update(User $user, PaoObjectifOperationnel $paoObjectifOperationnel): bool
    {
        $directionId = $this->resolveDirectionIdForOperationnel($paoObjectifOperationnel);

        return $this->canWriteDirection($user, $directionId);
    }

    public function delete(User $user, PaoObjectifOperationnel $paoObjectifOperationnel): bool
    {
        return $this->update($user, $paoObjectifOperationnel);
    }

    public function restore(User $user, PaoObjectifOperationnel $paoObjectifOperationnel): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaoObjectifOperationnel $paoObjectifOperationnel): bool
    {
        return false;
    }

    private function resolveDirectionIdForOperationnel(
        PaoObjectifOperationnel $paoObjectifOperationnel
    ): ?int {
        return $this->resolvePaoScopeForOperationnel($paoObjectifOperationnel)['direction_id'];
    }

    /**
     * @return array{pao_id: ?int, direction_id: ?int}
     */
    private function resolvePaoScopeForOperationnel(
        PaoObjectifOperationnel $paoObjectifOperationnel
    ): array {
        $row = $paoObjectifOperationnel
            ->objectifStrategique()
            ->join('pao_axes', 'pao_axes.id', '=', 'pao_objectifs_strategiques.pao_axe_id')
            ->join('paos', 'paos.id', '=', 'pao_axes.pao_id')
            ->first(['paos.id as pao_id', 'paos.direction_id as direction_id']);

        return [
            'pao_id' => $row?->pao_id !== null ? (int) $row->pao_id : null,
            'direction_id' => $row?->direction_id !== null ? (int) $row->direction_id : null,
        ];
    }
}
