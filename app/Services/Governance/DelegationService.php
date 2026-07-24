<?php

namespace App\Services\Governance;

use App\Models\Delegation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DelegationService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{rows:LengthAwarePaginator<int, Delegation>,summary:array<string, int>,filters:array<string, mixed>}
     */
    public function directory(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $query = Delegation::query()->with([
            'delegant:id,name,email,role,direction_id,service_id',
            'delegue:id,name,email,role,direction_id,service_id',
            'direction:id,code,libelle',
            'service:id,code,libelle',
            'createdBy:id,name',
            'cancelledBy:id,name',
        ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, (string) $filters['sort']);

        $rows = $query
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $now = now();
        $summaryQuery = Delegation::query();

        return [
            'rows' => $rows,
            'filters' => $filters,
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'active' => (clone $summaryQuery)
                    ->where('statut', 'active')
                    ->where('date_debut', '<=', $now)
                    ->where('date_fin', '>=', $now)
                    ->count(),
                'scheduled' => (clone $summaryQuery)
                    ->where('statut', 'active')
                    ->where('date_debut', '>', $now)
                    ->count(),
                'expires_soon' => (clone $summaryQuery)
                    ->where('statut', 'active')
                    ->where('date_debut', '<=', $now)
                    ->whereBetween('date_fin', [$now, $now->copy()->addDays(7)])
                    ->count(),
                'expired' => (clone $summaryQuery)
                    ->where('statut', 'active')
                    ->where('date_fin', '<', $now)
                    ->count(),
                'cancelled' => (clone $summaryQuery)->where('statut', 'cancelled')->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, User $actor): Delegation
    {
        return DB::transaction(function () use ($validated, $actor): Delegation {
            $delegate = User::query()
                ->whereKey((int) $validated['delegue_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $delegant = User::query()->findOrFail((int) $validated['delegant_id']);

            $this->validatePeopleAndScope($validated, $delegant, $delegate);

            $scope = (string) $validated['role_scope'];
            $serviceId = $scope === Delegation::SCOPE_SERVICE ? (int) $validated['service_id'] : null;
            $startsAt = Carbon::parse((string) $validated['date_debut']);
            $endsAt = Carbon::parse((string) $validated['date_fin']);

            $overlapExists = Delegation::query()
                ->where('delegue_id', $delegate->id)
                ->where('role_scope', $scope)
                ->where('direction_id', (int) $validated['direction_id'])
                ->when(
                    $serviceId === null,
                    fn (Builder $query): Builder => $query->whereNull('service_id'),
                    fn (Builder $query): Builder => $query->where('service_id', $serviceId),
                )
                ->where('statut', 'active')
                ->where('date_debut', '<', $endsAt)
                ->where('date_fin', '>', $startsAt)
                ->exists();

            if ($overlapExists) {
                throw ValidationException::withMessages([
                    'date_debut' => 'Une délégation chevauche déjà cette période pour le même bénéficiaire et le même périmètre.',
                ]);
            }

            return Delegation::query()->create([
                'delegant_id' => $delegant->id,
                'delegue_id' => $delegate->id,
                'role_scope' => $scope,
                'direction_id' => (int) $validated['direction_id'],
                'service_id' => $serviceId,
                'permissions' => array_values(array_unique((array) $validated['permissions'])),
                'motif' => trim((string) $validated['motif']),
                'date_debut' => $startsAt,
                'date_fin' => $endsAt,
                'statut' => 'active',
                'cree_par' => $actor->id,
            ]);
        });
    }

    /**
     * @return array{delegation:Delegation,before:array<string, mixed>}
     */
    public function cancel(Delegation $delegation, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($delegation, $actor, $reason): array {
            $lockedDelegation = Delegation::query()
                ->whereKey($delegation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedDelegation->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'motif_annulation' => 'Cette délégation est déjà annulée ou arrivée à échéance.',
                ]);
            }

            $before = $lockedDelegation->toArray();
            $lockedDelegation->forceFill([
                'statut' => 'cancelled',
                'annule_par' => $actor->id,
                'annule_le' => now(),
                'motif_annulation' => trim($reason),
            ])->save();

            return [
                'delegation' => $lockedDelegation->refresh(),
                'before' => $before,
            ];
        });
    }

    /**
     * @return Collection<int, Delegation>
     */
    public function activeDelegationsFor(User $user, ?string $permission = null): Collection
    {
        return $user->activeDelegations($permission);
    }

    public function canReviewServiceAction(User $user, ?int $directionId, ?int $serviceId): bool
    {
        return $user->hasDelegatedServiceScope($directionId, $serviceId, 'action_review');
    }

    public function canReviewDirectionAction(User $user, ?int $directionId): bool
    {
        return $user->hasDelegatedDirectionScope($directionId, 'action_review');
    }

    /**
     * @return Collection<int, User>
     */
    public function delegatedServiceReviewers(int $directionId, int $serviceId): Collection
    {
        if ($directionId <= 0 || $serviceId <= 0) {
            return collect();
        }

        $delegateIds = Delegation::query()
            ->active()
            ->where('role_scope', Delegation::SCOPE_SERVICE)
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->get()
            ->filter(static fn (Delegation $delegation): bool => $delegation->hasPermission('action_review'))
            ->pluck('delegue_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($delegateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $delegateIds->all())
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function delegatedDirectionReviewers(int $directionId): Collection
    {
        if ($directionId <= 0) {
            return collect();
        }

        $delegateIds = Delegation::query()
            ->active()
            ->where('role_scope', Delegation::SCOPE_DIRECTION)
            ->where('direction_id', $directionId)
            ->get()
            ->filter(static fn (Delegation $delegation): bool => $delegation->hasPermission('action_review'))
            ->pluck('delegue_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($delegateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $delegateIds->all())
            ->get();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validatePeopleAndScope(array &$validated, User $delegant, User $delegate): void
    {
        if (! $delegant->is_active || ! $delegate->is_active) {
            throw ValidationException::withMessages([
                'delegue_id' => 'Le délégant et le bénéficiaire doivent avoir un compte actif.',
            ]);
        }

        if ($delegate->isAgent()) {
            throw ValidationException::withMessages([
                'delegue_id' => 'Le bénéficiaire doit être un profil d’encadrement ou de pilotage.',
            ]);
        }

        if ($validated['role_scope'] === Delegation::SCOPE_DIRECTION) {
            if (! $delegant->hasRole(User::ROLE_DIRECTION)
                || (int) $delegant->direction_id !== (int) $validated['direction_id']
            ) {
                throw ValidationException::withMessages([
                    'delegant_id' => 'Le délégant doit être un directeur de la direction sélectionnée.',
                ]);
            }

            $validated['service_id'] = null;

            return;
        }

        $service = Service::query()->findOrFail((int) $validated['service_id']);
        if (! $service->actif || (int) $service->direction_id !== (int) $validated['direction_id']) {
            throw ValidationException::withMessages([
                'service_id' => 'Le service sélectionné ne correspond pas à la direction choisie.',
            ]);
        }

        if (! $delegant->hasRole(User::ROLE_SERVICE)
            || (int) $delegant->direction_id !== (int) $validated['direction_id']
            || (int) $delegant->service_id !== (int) $validated['service_id']
        ) {
            throw ValidationException::withMessages([
                'delegant_id' => 'Le délégant doit être un chef de service du périmètre sélectionné.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q:string,status:string,scope:string,permission:string,sort:string,per_page:int}
     */
    private function normalizeFilters(array $input): array
    {
        $status = $this->allowedString($input['status'] ?? null, ['all', 'active', 'scheduled', 'expiring', 'expired', 'cancelled'], 'all');
        $scope = $this->allowedString($input['scope'] ?? null, ['all', Delegation::SCOPE_DIRECTION, Delegation::SCOPE_SERVICE], 'all');
        $permission = $this->allowedString($input['permission'] ?? null, ['all', ...Delegation::AVAILABLE_PERMISSIONS], 'all');
        $sort = $this->allowedString($input['sort'] ?? null, ['newest', 'oldest', 'end_soon'], 'newest');
        $perPage = is_scalar($input['per_page'] ?? null) ? (int) $input['per_page'] : 20;

        return [
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 120) : '',
            'status' => $status,
            'scope' => $scope,
            'permission' => $permission,
            'sort' => $sort,
            'per_page' => in_array($perPage, [10, 20, 50], true) ? $perPage : 20,
        ];
    }

    /**
     * @param  Builder<Delegation>  $query
     * @param  array{q:string,status:string,scope:string,permission:string,sort:string,per_page:int}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $search = '%'.$filters['q'].'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->whereLike('motif', $search)
                    ->orWhereHas('delegant', fn (Builder $userQuery): Builder => $userQuery->whereLike('name', $search)->orWhereLike('email', $search))
                    ->orWhereHas('delegue', fn (Builder $userQuery): Builder => $userQuery->whereLike('name', $search)->orWhereLike('email', $search))
                    ->orWhereHas('direction', fn (Builder $directionQuery): Builder => $directionQuery->whereLike('code', $search)->orWhereLike('libelle', $search))
                    ->orWhereHas('service', fn (Builder $serviceQuery): Builder => $serviceQuery->whereLike('code', $search)->orWhereLike('libelle', $search));
            });
        }

        $now = now();
        match ($filters['status']) {
            'active' => $query->where('statut', 'active')->where('date_debut', '<=', $now)->where('date_fin', '>=', $now),
            'scheduled' => $query->where('statut', 'active')->where('date_debut', '>', $now),
            'expiring' => $query->where('statut', 'active')->where('date_debut', '<=', $now)->whereBetween('date_fin', [$now, $now->copy()->addDays(7)]),
            'expired' => $query->where('statut', 'active')->where('date_fin', '<', $now),
            'cancelled' => $query->where('statut', 'cancelled'),
            default => null,
        };

        if ($filters['scope'] !== 'all') {
            $query->where('role_scope', $filters['scope']);
        }

        if ($filters['permission'] !== 'all') {
            $query->whereJsonContains('permissions', $filters['permission']);
        }
    }

    /** @param Builder<Delegation> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->oldest('id'),
            'end_soon' => $query->orderBy('date_fin')->latest('id'),
            default => $query->latest('id'),
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedString(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
