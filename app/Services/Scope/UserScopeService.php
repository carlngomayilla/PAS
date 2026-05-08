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
            return $query->where(function (Builder $scoped) use ($user, $directionColumn, $serviceColumn, $ownerColumn): void {
                $hasConstraint = false;

                if ($ownerColumn) {
                    $scoped->orWhere($ownerColumn, $user->id);
                    $hasConstraint = true;
                }

                if ($serviceColumn && $user->service_id) {
                    $scoped->orWhere($serviceColumn, $user->service_id);
                    $hasConstraint = true;
                }

                if ($directionColumn && $user->direction_id) {
                    $scoped->orWhere($directionColumn, $user->direction_id);
                    $hasConstraint = true;
                }

                if (! $hasConstraint) {
                    $scoped->whereRaw('1 = 0');
                }
            });
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
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
