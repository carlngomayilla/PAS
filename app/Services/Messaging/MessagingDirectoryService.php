<?php

namespace App\Services\Messaging;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MessagingDirectoryService
{

    /**
     * @return Builder<User>
     */
    public function visibleUsersQuery(User $viewer): Builder
    {
        $query = User::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ]);

        if ($viewer->hasGlobalReadAccess()) {
            return $query;
        }

        $strategicRoles = [
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN,
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_COLLABORATEUR,
            User::ROLE_CABINET,
        ];

        return $query->where(function (Builder $scoped) use ($viewer, $strategicRoles): void {
            $scoped->whereIn('role', $strategicRoles)
                ->orWhere('id', $viewer->id);

            if ($viewer->direction_id !== null) {
                $scoped->orWhere('direction_id', (int) $viewer->direction_id);
            }

            if ($viewer->service_id !== null) {
                $scoped->orWhere('service_id', (int) $viewer->service_id);
            }
        });
    }

    public function canContactUser(User $viewer, User $target): bool
    {
        return (clone $this->visibleUsersQuery($viewer))
            ->whereKey($target->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, User>
     */
    public function visibleUsers(User $viewer, array $filters = [], int $limit = 18): Collection
    {
        $query = $this->applyUserFilters($this->visibleUsersQuery($viewer), $filters)
            ->orderByRaw("case when id = ? then 0 else 1 end", [$viewer->id])
            ->orderBy('name');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $this->enrichUsers($query->get());
    }

    public function collaboratorCard(User $viewer, ?User $subject): ?array
    {
        if (! $subject instanceof User || ! $this->canContactUser($viewer, $subject)) {
            return null;
        }

        $users = $this->enrichUsers(
            $this->visibleUsersQuery($viewer)
                ->orderBy('name')
                ->get()
        );

        /** @var User|null $resolved */
        $resolved = $users->firstWhere('id', $subject->id);
        if (! $resolved instanceof User) {
            return null;
        }

        $supervisor = $this->resolveSupervisor($resolved, $users);
        $relatedUsers = $users
            ->reject(fn (User $user): bool => $user->id === $resolved->id)
            ->filter(function (User $user) use ($resolved): bool {
                if ($resolved->service_id !== null && $user->service_id === $resolved->service_id) {
                    return true;
                }

                return $resolved->direction_id !== null && $user->direction_id === $resolved->direction_id;
            })
            ->take(6)
            ->values();

        return [
            'user' => $resolved,
            'presence' => $resolved->presence_meta,
            'supervisor' => $supervisor,
            'related_users' => $relatedUsers,
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    private function applyUserFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $scoped) use ($search): void {
                $scoped->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('agent_fonction', 'like', '%' . $search . '%')
                    ->orWhere('agent_matricule', 'like', '%' . $search . '%');
            });
        }

        $directionId = isset($filters['direction_id']) ? (int) $filters['direction_id'] : 0;
        if ($directionId > 0) {
            $query->where('direction_id', $directionId);
        }

        $serviceId = isset($filters['service_id']) ? (int) $filters['service_id'] : 0;
        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }

        $role = trim((string) ($filters['role'] ?? ''));
        if ($role !== '') {
            $query->where('role', $role);
        }

        return $query;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function enrichUsers(Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $presenceMap = $this->presenceMap($users);

        return $users->map(function (User $user) use ($presenceMap): User {
            $presence = $presenceMap[$user->id] ?? $this->buildPresenceMeta(null);
            $user->setAttribute('presence_meta', $presence);

            return $user;
        });
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{label:string,tone:string,last_activity:?Carbon}>
     */
    private function presenceMap(Collection $users): array
    {
        $rows = DB::table(config('session.table', 'sessions'))
            ->selectRaw('user_id, max(last_activity) as last_activity')
            ->whereIn('user_id', $users->pluck('id')->all())
            ->groupBy('user_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $lastActivity = isset($row->last_activity)
                ? Carbon::createFromTimestamp((int) $row->last_activity)
                : null;

            $map[(int) $row->user_id] = $this->buildPresenceMeta($lastActivity);
        }

        foreach ($users as $user) {
            $map[$user->id] ??= $this->buildPresenceMeta(null);
        }

        return $map;
    }

    /**
     * @return array{label:string,tone:string,last_activity:?Carbon}
     */
    private function buildPresenceMeta(?Carbon $lastActivity): array
    {
        if (! $lastActivity instanceof Carbon) {
            return [
                'label' => 'Absent',
                'tone' => 'neutral',
                'last_activity' => null,
            ];
        }

        $minutes = $lastActivity->diffInMinutes(now());

        if ($minutes <= 10) {
            return [
                'label' => 'En ligne',
                'tone' => 'success',
                'last_activity' => $lastActivity,
            ];
        }

        if ($minutes <= 720) {
            return [
                'label' => 'Hors ligne',
                'tone' => 'info',
                'last_activity' => $lastActivity,
            ];
        }

        return [
            'label' => 'Absent',
            'tone' => 'neutral',
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function resolveSupervisor(User $subject, Collection $users): ?User
    {
        /** @var User|null $dg */
        $dg = $users->first(fn (User $user): bool => $user->hasRole(User::ROLE_DG));

        if ($subject->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG)) {
            return null;
        }

        if ($subject->hasRole(User::ROLE_CABINET, User::ROLE_COLLABORATEUR)) {
            /** @var User|null $directionLead */
            $directionLead = $users->first(function (User $user) use ($subject): bool {
                return $user->direction_id === $subject->direction_id && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_DG);
            });

            return $directionLead ?? $dg;
        }

        if ($subject->hasRole(User::ROLE_DIRECTION)) {
            return $dg;
        }

        if ($subject->hasRole(User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SERVICE, User::ROLE_CHEF_UNITE, User::ROLE_CHEF_UNITE_SCIQ, User::ROLE_CHEF_UNITE_CABINET, User::ROLE_CHEF_UNITE_UCAS)) {
            /** @var User|null $directionLead */
            $directionLead = $users->first(function (User $user) use ($subject): bool {
                return $user->id !== $subject->id
                    && $user->direction_id === $subject->direction_id
                    && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_DG);
            });

            return $directionLead ?? $dg;
        }

        /** @var User|null $serviceLead */
        $serviceLead = $users->first(function (User $user) use ($subject): bool {
            return $user->id !== $subject->id
                && $subject->service_id !== null
                && $user->service_id === $subject->service_id
                && $user->hasRole(User::ROLE_SERVICE, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_CHEF_UNITE, User::ROLE_CHEF_UNITE_SCIQ, User::ROLE_CHEF_UNITE_CABINET, User::ROLE_CHEF_UNITE_UCAS);
        });

        if ($serviceLead instanceof User) {
            return $serviceLead;
        }

        /** @var User|null $directionLead */
        $directionLead = $users->first(function (User $user) use ($subject): bool {
            return $user->id !== $subject->id
                && $user->direction_id === $subject->direction_id
                && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_DG);
        });

        return $directionLead ?? $dg;
    }

}
