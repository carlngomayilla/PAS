<?php

namespace App\Services\Scope;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class UserScopeService
{
    public function applyToActions(Builder $query, User $user): Builder
    {
        return $this->apply($query, $user, ['responsable_id', 'agent_id', 'user_id']);
    }

    public function applyToPas(Builder $query, User $user): Builder
    {
        return $this->apply($query, $user);
    }

    public function applyToPao(Builder $query, User $user): Builder
    {
        return $this->apply($query, $user);
    }

    public function applyToPta(Builder $query, User $user): Builder
    {
        return $this->apply($query, $user);
    }

    public function apply(Builder $query, User $user, array $ownerColumns = []): Builder
    {
        if ($this->canSeeGlobal($user)) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $directionColumn = $this->column($table, ['direction_id']);
        $serviceColumn = $this->column($table, ['service_id']);
        $ownerColumn = $this->column($table, $ownerColumns);

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            if ($directionColumn && $user->direction_id) {
                return $query->where($directionColumn, $user->direction_id);
            }

            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
            if ($serviceColumn && $user->service_id) {
                return $query->where($serviceColumn, $user->service_id);
            }

            if ($directionColumn && $user->direction_id) {
                return $query->where($directionColumn, $user->direction_id);
            }

            return $query->whereRaw('1 = 0');
        }

        if ($user->isAgent()) {
            // A15 — Un agent ne voit QUE les enregistrements dont il est
            // explicitement responsable. L ancien scope incluait egalement
            // service_id et direction_id en OR : cela ouvrait toutes les actions
            // du service / de la direction au moindre agent. C est trop large
            // (cf. principe "qu un agent ne puisse voir que ses propres actions
            // ou celles autorisees").
            //
            // Si la table ne porte pas de colonne ownership (cas rare : table
            // agregee sans `*_id` user), on retombe sur `whereRaw('1 = 0')` plutot
            // que d ouvrir la vue.
            if (! $ownerColumn) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where($ownerColumn, $user->id);
        }

        if ($ownerColumn) {
            return $query->where($ownerColumn, $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    private function canSeeGlobal(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(
                User::ROLE_SUPER_ADMIN,
                User::ROLE_ADMIN,
                User::ROLE_DG
            );
    }

    private function column(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            // A31 — Cache memoise pour eviter l interrogation du schema info
            // a chaque scope query (appelee sur quasiment chaque requete metier).
            if (\App\Support\SchemaIntrospectionCache::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
