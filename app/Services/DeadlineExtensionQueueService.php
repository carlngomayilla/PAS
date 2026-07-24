<?php

namespace App\Services;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DeadlineExtensionQueueService
{
    /**
     * @return array{actionable_count:int,mine_count:int}
     */
    public function summaryFor(User $user): array
    {
        return [
            'actionable_count' => $this->actionableCount($user),
            'mine_count' => DeadlineExtensionRequest::query()
                ->where('requested_by', $user->id)
                ->count(),
        ];
    }

    /**
     * @return array{rows:LengthAwarePaginator,actionable_count:int,mine_count:int,view:string}
     */
    public function forUser(User $user, string $view = 'a_traiter', string $search = ''): array
    {
        $view = in_array($view, ['a_traiter', 'mes_demandes'], true) ? $view : 'a_traiter';
        $search = trim($search);
        $actionable = $this->actionableRequests($user);
        $actionableCount = $actionable->count();
        $mineCount = DeadlineExtensionRequest::query()
            ->where('requested_by', $user->id)
            ->count();

        if ($view === 'mes_demandes') {
            $query = $this->baseQuery()->where('requested_by', $user->id);
            $this->applySearch($query, $search);
            $rows = $query->paginate(20)->withQueryString();
        } else {
            $filtered = $search === ''
                ? $actionable
                : $actionable->filter(fn (DeadlineExtensionRequest $request): bool => $this->matchesSearch($request, $search));
            $rows = $this->paginateCollection($filtered->values(), 20);
        }

        return [
            'rows' => $rows,
            'actionable_count' => $actionableCount,
            'mine_count' => $mineCount,
            'view' => $view,
        ];
    }

    public function actionableCount(User $user): int
    {
        return $this->actionableRequests($user)->count();
    }

    /**
     * @return Collection<int, DeadlineExtensionRequest>
     */
    private function actionableRequests(User $user): Collection
    {
        $requests = $this->actionableCandidateQuery($user)->get();

        return $requests
            ->filter(fn (DeadlineExtensionRequest $request): bool => $this->isActionableBy($request, $user))
            ->values();
    }

    private function actionableCandidateQuery(User $user): Builder
    {
        $chefScopes = collect($user->delegatedServiceScopes('action_review'));
        if ($user->isServiceOrUnitChief()
            && ! $user->isPlanningControlChief()
            && $user->direction_id !== null
            && $user->service_id !== null) {
            $chefScopes->push([
                'direction_id' => (int) $user->direction_id,
                'service_id' => (int) $user->service_id,
            ]);
        }
        $chefScopes = $chefScopes
            ->unique(fn (array $scope): string => $scope['direction_id'].'-'.$scope['service_id'])
            ->values();

        $isController = $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ
        ) && ! $user->hasRole(User::ROLE_CHEF_PLANIFICATION);
        $isFinalApprover = $user->hasRole(User::ROLE_DG, User::ROLE_CHEF_PLANIFICATION);

        return $this->baseQuery()
            ->where(function (Builder $candidateQuery) use ($user, $chefScopes, $isController, $isFinalApprover): void {
                $candidateQuery->where(function (Builder $ownComplementQuery) use ($user): void {
                    $ownComplementQuery
                        ->where('status', DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE)
                        ->where('requested_by', $user->id);
                });

                if ($chefScopes->isNotEmpty()) {
                    $candidateQuery->orWhere(function (Builder $chefQuery) use ($chefScopes): void {
                        $chefQuery
                            ->whereIn('status', [
                                DeadlineExtensionRequest::STATUS_SOUMISE,
                                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
                            ])
                            ->whereHas('action.pta', function (Builder $ptaQuery) use ($chefScopes): void {
                                $ptaQuery->where(function (Builder $scopeQuery) use ($chefScopes): void {
                                    foreach ($chefScopes as $scope) {
                                        $scopeQuery->orWhere(function (Builder $serviceQuery) use ($scope): void {
                                            $serviceQuery
                                                ->where('direction_id', (int) $scope['direction_id'])
                                                ->where('service_id', (int) $scope['service_id']);
                                        });
                                    }
                                });
                            });
                    });
                }

                if ($isController) {
                    $candidateQuery->orWhereIn('status', [
                        DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE,
                        DeadlineExtensionRequest::STATUS_APPROUVEE,
                    ]);
                }

                if ($isFinalApprover) {
                    $candidateQuery->orWhereIn('status', [
                        DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
                        DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
                    ]);
                }
            });
    }

    private function isActionableBy(DeadlineExtensionRequest $request, User $user): bool
    {
        $action = $request->action;
        if ($action === null) {
            return false;
        }

        return match ((string) $request->status) {
            DeadlineExtensionRequest::STATUS_SOUMISE,
            DeadlineExtensionRequest::STATUS_EN_ANALYSE => $user->can('reviewDeadlineExtensionByChef', $action),
            DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE => (int) $request->requested_by === (int) $user->id,
            DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE => $user->can('reviewDeadlineExtensionByController', $action),
            DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
            DeadlineExtensionRequest::STATUS_TRANSMISE_DG => $user->can('reviewDeadlineExtensionFinal', $action),
            DeadlineExtensionRequest::STATUS_APPROUVEE => $user->can('applyDeadlineExtension', $action),
            default => false,
        };
    }

    private function baseQuery(): Builder
    {
        return DeadlineExtensionRequest::query()
            ->with([
                'action:id,pta_id,libelle,responsable_id',
                'action.pta:id,direction_id,service_id,titre',
                'sousAction:id,action_id,libelle',
                'requestedBy:id,name,role',
                'chefReviewedBy:id,name',
                'sciqReviewedBy:id,name',
                'finalDecidedBy:id,name',
                'appliedBy:id,name',
            ])
            ->latest('created_at')
            ->latest('id');
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery
                ->where('motif', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhereHas('action', fn (Builder $actionQuery) => $actionQuery->where('libelle', 'like', "%{$search}%"))
                ->orWhereHas('sousAction', fn (Builder $subActionQuery) => $subActionQuery->where('libelle', 'like', "%{$search}%"))
                ->orWhereHas('requestedBy', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
        });
    }

    private function matchesSearch(DeadlineExtensionRequest $request, string $search): bool
    {
        $haystack = implode(' ', [
            $request->motif,
            $request->status,
            $request->action?->libelle,
            $request->sousAction?->libelle,
            $request->requestedBy?->name,
        ]);

        return Str::contains(Str::lower($haystack), Str::lower($search));
    }

    /**
     * @param  Collection<int, DeadlineExtensionRequest>  $items
     */
    private function paginateCollection(Collection $items, int $perPage): LengthAwarePaginator
    {
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]
        );
    }
}
