<?php

namespace App\Services\Governance;

use App\Models\DeletionRequest;
use App\Models\User;
use App\Models\UserAssignmentHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GovernanceQueueService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     rows:LengthAwarePaginator<int, DeletionRequest>,
     *     summary:array<string, int>,
     *     filters:array<string, mixed>,
     *     canReview:bool,
     *     assignmentHistory:Collection<int, UserAssignmentHistory>,
     *     transferUserOptions:Collection<int, User>
     * }
     */
    public function deletionRequests(User $actor, array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $canReview = $actor->isSuperAdmin();
        $scope = DeletionRequest::query();
        if (! $canReview) {
            $scope->where('requested_by', $actor->id);
        }

        $query = (clone $scope)->with([
            'requester:id,name,email',
            'reviewer:id,name,email',
        ]);
        $this->applyFilters($query, $filters);
        $this->applySort($query, (string) $filters['sort']);

        $openStatuses = [
            DeletionRequest::STATUS_PENDING,
            DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
        ];
        $approvedStatuses = [
            DeletionRequest::STATUS_DELETED,
            DeletionRequest::STATUS_DISABLED,
            DeletionRequest::STATUS_ARCHIVED,
            DeletionRequest::STATUS_CORRECTED,
        ];

        return [
            'rows' => $query->paginate((int) $filters['per_page'])->withQueryString(),
            'summary' => [
                'total' => (clone $scope)->count(),
                'open' => (clone $scope)->whereIn('status', $openStatuses)->count(),
                'pending' => (clone $scope)->where('status', DeletionRequest::STATUS_PENDING)->count(),
                'approved' => (clone $scope)->whereIn('status', $approvedStatuses)->count(),
                'rejected' => (clone $scope)->where('status', DeletionRequest::STATUS_REJECTED)->count(),
                'complement' => (clone $scope)->where('status', DeletionRequest::STATUS_COMPLEMENT_REQUESTED)->count(),
            ],
            'filters' => $filters,
            'canReview' => $canReview,
            'assignmentHistory' => $canReview ? $this->assignmentHistory() : collect(),
            'transferUserOptions' => $canReview
                ? User::query()
                    ->with(['direction:id,code', 'service:id,code'])
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->limit(500)
                    ->get(['id', 'name', 'direction_id', 'service_id'])
                : collect(),
        ];
    }

    /** @return Collection<int, UserAssignmentHistory> */
    private function assignmentHistory(): Collection
    {
        return UserAssignmentHistory::query()
            ->with([
                'user:id,name,email',
                'actor:id,name',
                'replacement:id,name',
                'previousDirection:id,code,libelle',
                'newDirection:id,code,libelle',
                'previousService:id,code,libelle',
                'newService:id,code,libelle',
                'previousUniteDg:id,code,libelle',
                'newUniteDg:id,code,libelle',
            ])
            ->latest('changed_at')
            ->latest('id')
            ->limit(15)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q:string,status:string,module:string,sort:string,per_page:int}
     */
    private function normalizeFilters(array $input): array
    {
        $statuses = [
            'all',
            DeletionRequest::STATUS_PENDING,
            DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
            DeletionRequest::STATUS_DELETED,
            DeletionRequest::STATUS_DISABLED,
            DeletionRequest::STATUS_ARCHIVED,
            DeletionRequest::STATUS_REJECTED,
            DeletionRequest::STATUS_CORRECTED,
        ];
        $modules = ['all', 'referentiel_utilisateur', 'pas', 'pao', 'pta', 'action'];
        $sorts = ['newest', 'oldest', 'pending_first'];
        $perPage = is_scalar($input['per_page'] ?? null) ? (int) $input['per_page'] : 20;

        return [
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 120) : '',
            'status' => $this->allowedString($input['status'] ?? null, $statuses, 'all'),
            'module' => $this->allowedString($input['module'] ?? null, $modules, 'all'),
            'sort' => $this->allowedString($input['sort'] ?? null, $sorts, 'newest'),
            'per_page' => in_array($perPage, [10, 20, 50], true) ? $perPage : 20,
        ];
    }

    /**
     * @param  Builder<DeletionRequest>  $query
     * @param  array{q:string,status:string,module:string,sort:string,per_page:int}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $search = '%'.$filters['q'].'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->whereLike('entity_label', $search)
                    ->orWhereLike('reason', $search)
                    ->orWhereLike('reviewer_note', $search)
                    ->orWhereHas('requester', fn (Builder $requesterQuery): Builder => $requesterQuery->whereLike('name', $search)->orWhereLike('email', $search));
            });
        }

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if ($filters['module'] !== 'all') {
            $query->where('module', $filters['module']);
        }
    }

    /** @param Builder<DeletionRequest> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->oldest('id'),
            'pending_first' => $query
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [
                    DeletionRequest::STATUS_PENDING,
                    DeletionRequest::STATUS_COMPLEMENT_REQUESTED,
                ])
                ->latest('id'),
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
