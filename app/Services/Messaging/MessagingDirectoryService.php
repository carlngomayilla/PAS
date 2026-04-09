<?php

namespace App\Services\Messaging;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessagingDirectoryService
{
    /**
     * @var list<string>
     */
    private const DIRECTION_ORDER = ['DG', 'DGA', 'SCIQ', 'UCAS', 'DS', 'DSIC', 'DAF'];

    /**
     * @var array<string, list<string>>
     */
    private const SERVICE_ORDER = [
        'DG' => ['DIRGEN', 'CAB'],
        'DGA' => ['DIRECTION', 'SECDGA'],
        'SCIQ' => ['CTRLINT'],
        'UCAS' => ['UCAS', 'ACCUEIL'],
        'DS' => ['DIRECTION', 'ENB', 'EB', 'PLANIF'],
        'DSIC' => ['DIRECTION', 'SIRS', 'CRP', 'GDS'],
        'DAF' => ['DIRECTION', 'AJARH', 'SFC', 'AMG'],
    ];

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

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function orgChart(User $viewer, array $filters = []): array
    {
        $users = $this->enrichUsers(
            $this->applyUserFilters($this->visibleUsersQuery($viewer), $filters)
                ->orderBy('name')
                ->get()
        );

        $directionIds = $users->pluck('direction_id')->filter()->unique()->values()->all();
        $serviceIds = $users->pluck('service_id')->filter()->unique()->values()->all();

        $directions = Direction::query()
            ->whereIn('id', $directionIds)
            ->orderBy('libelle')
            ->get()
            ->keyBy('id');

        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->orderBy('libelle')
            ->get()
            ->groupBy('direction_id');

        $strategic = [
            'leadership' => $users->filter(fn (User $user): bool => $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG))->values(),
            'cabinet' => $users->filter(fn (User $user): bool => $user->hasRole(User::ROLE_CABINET))->values(),
            'planification' => $users->filter(fn (User $user): bool => $user->hasRole(User::ROLE_PLANIFICATION))->values(),
        ];

        $sortedDirectionIds = collect($directionIds)
            ->sortBy(fn (int $directionId): int => $this->directionRank((string) ($directions->get($directionId)?->code ?? '')))
            ->values();

        $directionBlocks = $sortedDirectionIds
            ->map(function (int $directionId) use ($directions, $services, $users): ?array {
                /** @var Direction|null $direction */
                $direction = $directions->get($directionId);
                if (! $direction instanceof Direction) {
                    return null;
                }

                $directionUsers = $users->where('direction_id', $directionId)->values();
                $leaders = $directionUsers
                    ->filter(fn (User $user): bool => $user->hasRole(User::ROLE_DIRECTION, User::ROLE_DG))
                    ->values();
                $support = $directionUsers
                    ->filter(fn (User $user): bool => $user->hasRole(User::ROLE_CABINET))
                    ->values();
                $serviceBlocks = collect($services->get($directionId, collect()))
                    ->sortBy(fn (Service $service): int => $this->serviceRank((string) $direction->code, (string) $service->code))
                    ->map(function (Service $service) use ($directionUsers): ?array {
                        $serviceUsers = $directionUsers->where('service_id', $service->id)->values();
                        if ($serviceUsers->isEmpty()) {
                            return null;
                        }

                        $serviceHeads = $serviceUsers
                            ->filter(function (User $user) use ($service): bool {
                                if ($user->hasRole(User::ROLE_SERVICE, User::ROLE_PLANIFICATION)) {
                                    return true;
                                }

                                return in_array((string) $service->code, ['DIRECTION', 'DIRGEN'], true)
                                    && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_DG);
                            });

                        return [
                            'service' => $service,
                            'heads' => $this->sortUsersForOrgChart($serviceHeads),
                            'members' => $this->sortUsersForOrgChart(
                                $serviceUsers
                                    ->reject(fn (User $user): bool => $serviceHeads->contains('id', $user->id))
                            )
                                ->values(),
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'direction' => $direction,
                    'leaders' => $this->sortUsersForOrgChart($leaders),
                    'support' => $this->sortUsersForOrgChart($support),
                    'floating_members' => $this->sortUsersForOrgChart($directionUsers
                        ->reject(fn (User $user): bool => $leaders->contains('id', $user->id))
                        ->reject(fn (User $user): bool => $support->contains('id', $user->id))
                        ->filter(fn (User $user): bool => $user->service_id === null)
                    ),
                    'services' => $serviceBlocks,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'strategic' => $strategic,
            'directions' => $directionBlocks,
            'tree' => $this->buildOrgTree($viewer, $strategic, $directionBlocks),
            'users' => $users,
        ];
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

        if ($subject->hasRole(User::ROLE_CABINET)) {
            /** @var User|null $directionLead */
            $directionLead = $users->first(function (User $user) use ($subject): bool {
                return $user->direction_id === $subject->direction_id && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_DG);
            });

            return $directionLead ?? $dg;
        }

        if ($subject->hasRole(User::ROLE_DIRECTION)) {
            return $dg;
        }

        if ($subject->hasRole(User::ROLE_PLANIFICATION, User::ROLE_SERVICE)) {
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
                && $user->hasRole(User::ROLE_SERVICE, User::ROLE_PLANIFICATION);
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

    /**
     * @param  array<string, Collection<int, User>>  $strategic
     * @param  array<int, array<string, mixed>>  $directionBlocks
     * @return array<int, array<string, mixed>>
     */
    private function buildOrgTree(User $viewer, array $strategic, array $directionBlocks): array
    {
        /** @var Collection<int, User> $leadership */
        $leadership = collect($strategic['leadership'] ?? collect())->values();
        /** @var User|null $rootLeader */
        $rootLeader = $leadership->first(fn (User $user): bool => $user->hasRole(User::ROLE_DG))
            ?? $leadership->first(fn (User $user): bool => $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN))
            ?? $leadership->first();

        $directionChildren = collect($directionBlocks)
            ->map(fn (array $block): ?array => $this->directionTreeNode($block, $viewer, $rootLeader))
            ->filter()
            ->values()
            ->all();

        $supportUsers = $this->sortUsersForOrgChart($leadership
            ->reject(fn (User $user): bool => $rootLeader instanceof User && (int) $user->id === (int) $rootLeader->id)
            ->filter(fn (User $user): bool => $user->direction_id === null && $user->service_id === null)
            ->filter(fn (User $user): bool => $rootLeader === null || (int) $user->id !== (int) $rootLeader->id)
            ->unique('id')
            ->values());

        if ($supportUsers->isNotEmpty()) {
            array_unshift($directionChildren, [
                'key' => 'direction-pilotage-global',
                'type' => 'direction',
                'label' => 'Pilotage global',
                'subtitle' => 'Cabinet, planification et coordination DG',
                'theme' => 'blue',
                'count' => $supportUsers->count(),
                'expanded' => true,
                'children' => $supportUsers
                    ->map(fn (User $user): array => $this->userTreeNode($user, $viewer))
                    ->values()
                    ->all(),
            ]);
        }

        if ($rootLeader instanceof User) {
            $rootNode = $this->userTreeNode($rootLeader, $viewer, 'DG');
            $rootNode['key'] = 'org-root-dg';
            $rootNode['type'] = 'root_user';
            $rootNode['subtitle'] = (string) ($rootLeader->agent_fonction ?: 'Direction Generale');
            $rootNode['scope'] = 'DG / Direction generale';
            $rootNode['theme'] = 'blue';
            $rootNode['count'] = count($directionChildren);
            $rootNode['expanded'] = true;
            $rootNode['children'] = $directionChildren;

            return [$rootNode];
        }

        return [[
            'key' => 'root-dg-fallback',
            'type' => 'root',
            'label' => 'DG',
            'subtitle' => 'Organigramme detaille DG -> Directions -> Services -> Agents',
            'theme' => 'blue',
            'count' => count($directionChildren),
            'expanded' => true,
            'children' => $directionChildren,
        ]];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function directionTreeNode(array $block, User $viewer, ?User $rootLeader = null): ?array
    {
        /** @var Direction $direction */
        $direction = $block['direction'];
        /** @var Collection<int, User> $leaders */
        $leaders = $block['leaders'];
        if ($rootLeader instanceof User) {
            $leaders = $leaders
                ->reject(fn (User $user): bool => (int) $user->id === (int) $rootLeader->id)
                ->values();
        }
        /** @var Collection<int, User> $support */
        $support = $block['support'];
        /** @var Collection<int, User> $floatingMembers */
        $floatingMembers = collect($block['floating_members'] ?? [])->values();
        $serviceBlocks = collect($block['services'] ?? []);
        /** @var User|null $primaryLeader */
        $primaryLeader = $leaders->first();
        $extraLeaders = $leaders->skip(1)->values();

        $children = [];

        $supportMembers = $this->sortUsersForOrgChart($support
            ->concat($extraLeaders)
            ->concat($floatingMembers)
            ->unique('id')
            ->values());

        if ($supportMembers->isNotEmpty()) {
            /** @var User|null $supportLead */
            $supportLead = $support->first() ?? $extraLeaders->first();
            $children[] = [
                'key' => 'direction-support-'.$direction->id,
                'type' => 'service',
                'label' => 'Direction',
                'subtitle' => 'Cabinet, coordination et appui',
                'theme' => 'amber',
                'manager_user_id' => $supportLead?->id,
                'manager_name' => $supportLead?->name,
                'manager_title' => $supportLead?->agent_fonction ?: $supportLead?->roleLabel(),
                'manager_level' => $supportLead instanceof User ? $this->userHierarchyLevel($supportLead) : null,
                'manager_photo_url' => $supportLead?->profile_photo_url,
                'manager_initials' => $supportLead?->profile_initials,
                'detail' => $supportMembers->count() . ' profil(s) rattache(s)',
                'count' => $supportMembers->count(),
                'expanded' => true,
                'children' => $supportMembers
                    ->reject(fn (User $user): bool => $supportLead instanceof User && (int) $user->id === (int) $supportLead->id)
                    ->map(fn (User $user): array => $this->userTreeNode($user, $viewer))
                    ->values()
                    ->all(),
            ];
        }

        foreach ($serviceBlocks as $serviceBlock) {
            $children[] = $this->serviceTreeNode($serviceBlock, $viewer);
        }

        if ($children === []) {
            return null;
        }

        return [
            'key' => 'direction-'.$direction->id,
            'type' => 'direction',
            'label' => (string) ($direction->code ?: $direction->libelle),
            'subtitle' => (string) $direction->libelle,
            'theme' => 'rose',
            'manager_user_id' => $primaryLeader?->id,
            'manager_name' => $primaryLeader?->name,
            'manager_title' => $primaryLeader?->agent_fonction ?: $primaryLeader?->roleLabel(),
            'manager_level' => $primaryLeader instanceof User ? $this->userHierarchyLevel($primaryLeader) : null,
            'manager_photo_url' => $primaryLeader?->profile_photo_url,
            'manager_initials' => $primaryLeader?->profile_initials,
            'detail' => $serviceBlocks->count() . ' service(s) actif(s)',
            'count' => collect($children)->sum(function (array $node): int {
                if (isset($node['count'])) {
                    return (int) $node['count'];
                }

                return ($node['type'] ?? '') === 'user' ? 1 : 0;
            }),
            'expanded' => true,
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $serviceBlock
     * @return array<string, mixed>
     */
    private function serviceTreeNode(array $serviceBlock, User $viewer): array
    {
        /** @var Service $service */
        $service = $serviceBlock['service'];
        /** @var Collection<int, User> $heads */
        $heads = $serviceBlock['heads'];
        /** @var Collection<int, User> $members */
        $members = $serviceBlock['members'];
        /** @var User|null $primaryHead */
        $primaryHead = $heads->first();
        $extraHeads = $heads->skip(1)->values();

        $children = [];

        foreach ($extraHeads as $head) {
            $children[] = $this->userTreeNode($head, $viewer, 'Co-responsable');
        }

        foreach ($members as $member) {
            $children[] = $this->userTreeNode($member, $viewer);
        }

        return [
            'key' => 'service-'.$service->id,
            'type' => 'service',
            'label' => (string) ($service->code ?: $service->libelle),
            'subtitle' => (string) $service->libelle,
            'theme' => 'violet',
            'manager_user_id' => $primaryHead?->id,
            'manager_name' => $primaryHead?->name,
            'manager_title' => $primaryHead?->agent_fonction ?: $primaryHead?->roleLabel(),
            'manager_level' => $primaryHead instanceof User ? $this->userHierarchyLevel($primaryHead) : null,
            'manager_photo_url' => $primaryHead?->profile_photo_url,
            'manager_initials' => $primaryHead?->profile_initials,
            'detail' => $members->count() . ' agent(s) rattache(s)',
            'count' => $heads->count() + $members->count(),
            'expanded' => true,
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userTreeNode(User $user, User $viewer, ?string $emphasis = null): array
    {
        $presence = $user->getAttribute('presence_meta');
        if (! is_array($presence)) {
            $presence = $this->buildPresenceMeta(null);
        }

        return [
            'key' => 'user-'.$user->id,
            'type' => 'user',
            'label' => (string) $user->name,
            'subtitle' => (string) ($user->agent_fonction ?: $user->roleLabel()),
            'scope' => trim(($user->direction?->libelle ?? 'Sans direction').' / '.($user->service?->libelle ?? 'Sans service')),
            'photo_url' => $user->profile_photo_url,
            'initials' => $user->profile_initials,
            'role_label' => $user->roleLabel(),
            'hierarchy_level' => $this->userHierarchyLevel($user),
            'presence' => (string) ($presence['label'] ?? 'Absent'),
            'tone' => (string) ($presence['tone'] ?? 'neutral'),
            'theme' => $this->userTheme($user),
            'emphasis' => $emphasis,
            'user_id' => (int) $user->id,
            'is_current_user' => (int) $user->id === (int) $viewer->id,
            'count' => 1,
            'children' => [],
        ];
    }

    private function userTheme(User $user): string
    {
        return match ($user->role) {
            User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_DG => 'blue',
            User::ROLE_CABINET => 'amber',
            User::ROLE_PLANIFICATION => 'green',
            User::ROLE_DIRECTION => 'rose',
            User::ROLE_SERVICE => 'violet',
            default => 'slate',
        };
    }

    private function directionRank(string $code): int
    {
        $index = array_search($code, self::DIRECTION_ORDER, true);

        return $index === false ? 999 : $index;
    }

    private function serviceRank(string $directionCode, string $serviceCode): int
    {
        $order = self::SERVICE_ORDER[$directionCode] ?? [];
        $index = array_search($serviceCode, $order, true);

        return $index === false ? 999 : $index;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function sortUsersForOrgChart(Collection $users): Collection
    {
        return $users
            ->sort(function (User $left, User $right): int {
                $rankComparison = $this->userOrgRank($left) <=> $this->userOrgRank($right);
                if ($rankComparison !== 0) {
                    return $rankComparison;
                }

                $titleComparison = $this->userFunctionRank($left) <=> $this->userFunctionRank($right);
                if ($titleComparison !== 0) {
                    return $titleComparison;
                }

                return strcasecmp((string) $left->name, (string) $right->name);
            })
            ->values();
    }

    private function userOrgRank(User $user): int
    {
        return match (true) {
            $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_DG, User::ROLE_ADMIN) => 0,
            $user->hasRole(User::ROLE_DIRECTION) => 1,
            $user->hasRole(User::ROLE_CABINET) => 2,
            $user->hasRole(User::ROLE_PLANIFICATION, User::ROLE_SERVICE) => 3,
            default => 4,
        };
    }

    private function userHierarchyLevel(User $user): string
    {
        return match (true) {
            $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_DG, User::ROLE_ADMIN, User::ROLE_DIRECTION) => 'direction',
            $user->hasRole(User::ROLE_CABINET, User::ROLE_PLANIFICATION, User::ROLE_SERVICE) => 'service',
            default => 'agent',
        };
    }

    private function userFunctionRank(User $user): int
    {
        $function = Str::lower(trim((string) $user->agent_fonction));

        if ($function === '') {
            return 999;
        }

        return match (true) {
            str_contains($function, 'directeur general') => 0,
            str_contains($function, 'directeur') => 1,
            str_contains($function, 'chef service') => 2,
            str_contains($function, 'chef') => 3,
            str_contains($function, 'responsable') => 4,
            str_contains($function, 'conseiller') => 5,
            str_contains($function, 'secretaire') => 6,
            str_contains($function, 'assistant') => 7,
            default => 50,
        };
    }
}
