<?php

namespace App\Services;

use App\Models\Action;
use App\Models\BudgetOverrunRequest;
use App\Models\Direction;
use App\Models\FinancialTransaction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialMonitoringService
{
    public function canView(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(
                User::ROLE_DIRECTION,
                User::ROLE_SERVICE,
                User::ROLE_PLANIFICATION,
                User::ROLE_CHEF_PLANIFICATION,
                User::ROLE_SCIQ,
                User::ROLE_SCIQ_SUIVI_GLOBAL,
                User::ROLE_CHEF_UNITE_SCIQ,
                User::ROLE_DG
            );
    }

    public function canRecord(User $user): bool
    {
        if (! $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE) || $user->direction_id === null) {
            return false;
        }

        if ($user->relationLoaded('direction')) {
            return (string) ($user->direction?->code ?? '') === 'DAF';
        }

        return $user->direction()->where('code', 'DAF')->exists();
    }

    public function isDafDirector(User $user): bool
    {
        return $this->canRecord($user) && $user->hasRole(User::ROLE_DIRECTION);
    }

    public function scopedActions(User $user): Builder
    {
        $query = Action::query()->whereNotNull('montant_estime');

        if ($this->canRecord($user) || $user->hasGlobalReadAccess() || $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_DG
        )) {
            return $query;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return $query->whereHas('pta', fn (Builder $pta): Builder => $pta->where('direction_id', $user->direction_id));
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            return $query->whereHas('pta', fn (Builder $pta): Builder => $pta->where('service_id', $user->service_id));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  array{operation_type:string,amount:numeric,operated_on:string,payment_method?:string|null,reference?:string|null,beneficiary?:string|null,comment?:string|null}  $payload
     */
    public function record(Action $action, array $payload, User $actor): FinancialTransaction
    {
        if (! $this->canRecord($actor)) {
            abort(403, 'Seuls les responsables habilites de la DAF peuvent enregistrer une operation financiere.');
        }

        return DB::transaction(function () use ($action, $payload, $actor): FinancialTransaction {
            $lockedAction = Action::query()->with('pta:id,direction_id,service_id')->lockForUpdate()->findOrFail($action->id);
            $this->assertOperationWithinApprovedBudget($lockedAction, (string) $payload['operation_type'], (float) $payload['amount']);

            return FinancialTransaction::query()->create([
                ...$payload,
                'action_id' => $lockedAction->id,
                'recorded_by' => $actor->id,
            ]);
        }, attempts: 3);
    }

    public function requestOverrun(string $scopeType, int $scopeId, float $requestedExtra, string $reason, User $actor): BudgetOverrunRequest
    {
        if (! $this->canRecord($actor)) {
            abort(403, 'Seuls les responsables habilites de la DAF peuvent demander un depassement budgetaire.');
        }

        $baseBudget = $this->baseBudget($scopeType, $scopeId);
        if ($baseBudget === null) {
            throw ValidationException::withMessages(['scope_id' => 'Le perimetre budgetaire selectionne est introuvable.']);
        }

        return BudgetOverrunRequest::query()->create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'base_budget' => $baseBudget,
            'requested_extra' => $requestedExtra,
            'reason' => trim($reason),
            'requested_by' => $actor->id,
            'status' => $this->isDafDirector($actor)
                ? BudgetOverrunRequest::STATUS_PENDING_DG
                : BudgetOverrunRequest::STATUS_PENDING_DIRECTOR,
            'daf_director_id' => $this->isDafDirector($actor) ? $actor->id : null,
            'daf_director_reviewed_at' => $this->isDafDirector($actor) ? now() : null,
            'daf_director_note' => $this->isDafDirector($actor) ? 'Demande transmise par la Direction DAF.' : null,
        ]);
    }

    public function reviewOverrun(BudgetOverrunRequest $request, string $decision, string $note, User $actor): BudgetOverrunRequest
    {
        return DB::transaction(function () use ($request, $decision, $note, $actor): BudgetOverrunRequest {
            $lockedRequest = BudgetOverrunRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($lockedRequest->status === BudgetOverrunRequest::STATUS_PENDING_DIRECTOR) {
                if (! $this->isDafDirector($actor)) {
                    abort(403, 'Seule la Directrice DAF peut transmettre une demande de depassement a la DG.');
                }

                if (! in_array($decision, ['transmit', 'reject'], true)) {
                    throw ValidationException::withMessages(['decision' => 'Decision DAF invalide.']);
                }

                $lockedRequest->forceFill([
                    'status' => $decision === 'transmit' ? BudgetOverrunRequest::STATUS_PENDING_DG : BudgetOverrunRequest::STATUS_REJECTED,
                    'daf_director_id' => $actor->id,
                    'daf_director_reviewed_at' => now(),
                    'daf_director_note' => trim($note),
                ])->save();

                return $lockedRequest->refresh();
            }

            if ($lockedRequest->status !== BudgetOverrunRequest::STATUS_PENDING_DG || ! $actor->hasRole(User::ROLE_DG)) {
                abort(403, 'Cette demande ne peut pas etre traitee avec votre profil.');
            }

            if (! in_array($decision, ['approve', 'reject'], true)) {
                throw ValidationException::withMessages(['decision' => 'Decision DG invalide.']);
            }

            $lockedRequest->forceFill([
                'status' => $decision === 'approve' ? BudgetOverrunRequest::STATUS_APPROVED : BudgetOverrunRequest::STATUS_REJECTED,
                'dg_decided_by' => $actor->id,
                'dg_decided_at' => now(),
                'dg_note' => trim($note),
            ])->save();

            return $lockedRequest->refresh();
        }, attempts: 3);
    }

    /** @return array{budget:float,engaged:float,disbursed:float,remaining:float,engagement_rate:float,disbursement_rate:float} */
    public function actionSummary(Action $action): array
    {
        $budget = $this->effectiveBudget(BudgetOverrunRequest::SCOPE_ACTION, (int) $action->id, (float) ($action->montant_estime ?? 0));
        $totals = FinancialTransaction::query()
            ->where('action_id', $action->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'engagement' THEN amount ELSE 0 END), 0) as engaged")
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'decaissement' THEN amount ELSE 0 END), 0) as disbursed")
            ->first();
        $engaged = (float) ($totals?->engaged ?? 0);
        $disbursed = (float) ($totals?->disbursed ?? 0);

        return [
            'budget' => $budget,
            'engaged' => $engaged,
            'disbursed' => $disbursed,
            'remaining' => $budget - $disbursed,
            'engagement_rate' => $budget > 0 ? round($engaged / $budget * 100, 2) : 0.0,
            'disbursement_rate' => $budget > 0 ? round($disbursed / $budget * 100, 2) : 0.0,
        ];
    }

    /**
     * @return array{budget:float,engaged:float,disbursed:float,remaining:float,engagement_rate:float,disbursement_rate:float,actions_total:int}
     */
    public function dashboardSummary(User $user): ?array
    {
        if (! $this->canView($user)) {
            return null;
        }

        $actions = $this->scopedActions($user);
        $actionIds = (clone $actions)->select('id');
        $budget = (float) (clone $actions)->sum('montant_estime');
        $totals = FinancialTransaction::query()
            ->whereIn('action_id', $actionIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'engagement' THEN amount ELSE 0 END), 0) as engaged")
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'decaissement' THEN amount ELSE 0 END), 0) as disbursed")
            ->first();
        $engaged = (float) ($totals?->engaged ?? 0);
        $disbursed = (float) ($totals?->disbursed ?? 0);

        return [
            'budget' => $budget,
            'engaged' => $engaged,
            'disbursed' => $disbursed,
            'remaining' => $budget - $disbursed,
            'engagement_rate' => $budget > 0 ? round($engaged / $budget * 100, 2) : 0.0,
            'disbursement_rate' => $budget > 0 ? round($disbursed / $budget * 100, 2) : 0.0,
            'actions_total' => (clone $actions)->count(),
        ];
    }

    private function assertOperationWithinApprovedBudget(Action $action, string $operationType, float $amount): void
    {
        $summary = $this->actionSummary($action);
        $current = $operationType === FinancialTransaction::TYPE_COMMITMENT ? $summary['engaged'] : $summary['disbursed'];
        if ($current + $amount > $summary['budget']) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant depasse le budget approuve de cette action. Creez d abord une demande de depassement pour transmission DAF puis DG.',
            ]);
        }

        if ($action->pta?->service_id !== null) {
            $this->assertScopeWithinApprovedBudget(
                BudgetOverrunRequest::SCOPE_SERVICE,
                (int) $action->pta->service_id,
                $operationType,
                $amount,
                'service'
            );
        }

        if ($action->pta?->direction_id !== null) {
            $this->assertScopeWithinApprovedBudget(
                BudgetOverrunRequest::SCOPE_DIRECTION,
                (int) $action->pta->direction_id,
                $operationType,
                $amount,
                'direction'
            );
        }
    }

    private function assertScopeWithinApprovedBudget(string $scopeType, int $scopeId, string $operationType, float $amount, string $label): void
    {
        $baseBudget = $this->baseBudget($scopeType, $scopeId) ?? 0.0;
        $budget = $this->effectiveBudget($scopeType, $scopeId, $baseBudget);
        $totals = $this->scopeTransactions($scopeType, $scopeId);
        $current = $operationType === FinancialTransaction::TYPE_COMMITMENT ? $totals['engaged'] : $totals['disbursed'];

        if ($current + $amount > $budget) {
            throw ValidationException::withMessages([
                'amount' => "Le montant depasse le budget approuve du {$label}. Une demande de depassement de ce perimetre doit etre approuvee par la DG.",
            ]);
        }
    }

    private function effectiveBudget(string $scopeType, int $scopeId, float $baseBudget): float
    {
        return $baseBudget + $this->approvedExtraForScope($scopeType, $scopeId);
    }

    private function approvedExtraForScope(string $scopeType, int $scopeId): float
    {
        $approved = BudgetOverrunRequest::query()->where('status', BudgetOverrunRequest::STATUS_APPROVED);

        return match ($scopeType) {
            BudgetOverrunRequest::SCOPE_ACTION => (float) (clone $approved)
                ->where('scope_type', BudgetOverrunRequest::SCOPE_ACTION)
                ->where('scope_id', $scopeId)
                ->sum('requested_extra'),
            BudgetOverrunRequest::SCOPE_SERVICE => (float) (clone $approved)
                ->where(function (Builder $query) use ($scopeId): void {
                    $query->where(function (Builder $scopeQuery) use ($scopeId): void {
                        $scopeQuery->where('scope_type', BudgetOverrunRequest::SCOPE_SERVICE)->where('scope_id', $scopeId);
                    })->orWhere(function (Builder $scopeQuery) use ($scopeId): void {
                        $scopeQuery->where('scope_type', BudgetOverrunRequest::SCOPE_ACTION)
                            ->whereIn('scope_id', Action::query()->whereHas('pta', fn (Builder $pta): Builder => $pta->where('service_id', $scopeId))->select('id'));
                    });
                })->sum('requested_extra'),
            BudgetOverrunRequest::SCOPE_DIRECTION => (float) (clone $approved)
                ->where(function (Builder $query) use ($scopeId): void {
                    $query->where(function (Builder $scopeQuery) use ($scopeId): void {
                        $scopeQuery->where('scope_type', BudgetOverrunRequest::SCOPE_DIRECTION)->where('scope_id', $scopeId);
                    })->orWhere(function (Builder $scopeQuery) use ($scopeId): void {
                        $scopeQuery->where('scope_type', BudgetOverrunRequest::SCOPE_SERVICE)
                            ->whereIn('scope_id', Service::query()->where('direction_id', $scopeId)->select('id'));
                    })->orWhere(function (Builder $scopeQuery) use ($scopeId): void {
                        $scopeQuery->where('scope_type', BudgetOverrunRequest::SCOPE_ACTION)
                            ->whereIn('scope_id', Action::query()->whereHas('pta', fn (Builder $pta): Builder => $pta->where('direction_id', $scopeId))->select('id'));
                    });
                })->sum('requested_extra'),
            default => 0.0,
        };
    }

    /** @return array{engaged:float,disbursed:float} */
    private function scopeTransactions(string $scopeType, int $scopeId): array
    {
        $actions = match ($scopeType) {
            BudgetOverrunRequest::SCOPE_SERVICE => Action::query()->whereHas('pta', fn (Builder $pta): Builder => $pta->where('service_id', $scopeId)),
            BudgetOverrunRequest::SCOPE_DIRECTION => Action::query()->whereHas('pta', fn (Builder $pta): Builder => $pta->where('direction_id', $scopeId)),
            default => Action::query()->whereKey($scopeId),
        };

        $totals = FinancialTransaction::query()
            ->whereIn('action_id', $actions->select('id'))
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'engagement' THEN amount ELSE 0 END), 0) as engaged")
            ->selectRaw("COALESCE(SUM(CASE WHEN operation_type = 'decaissement' THEN amount ELSE 0 END), 0) as disbursed")
            ->first();

        return [
            'engaged' => (float) ($totals?->engaged ?? 0),
            'disbursed' => (float) ($totals?->disbursed ?? 0),
        ];
    }

    private function baseBudget(string $scopeType, int $scopeId): ?float
    {
        return match ($scopeType) {
            BudgetOverrunRequest::SCOPE_ACTION => Action::query()->whereKey($scopeId)->value('montant_estime'),
            BudgetOverrunRequest::SCOPE_SERVICE => Service::query()->whereKey($scopeId)->exists()
                ? (float) Action::query()->whereNotNull('montant_estime')->whereHas('pta', fn (Builder $pta): Builder => $pta->where('service_id', $scopeId))->sum('montant_estime')
                : null,
            BudgetOverrunRequest::SCOPE_DIRECTION => Direction::query()->whereKey($scopeId)->exists()
                ? (float) Action::query()->whereNotNull('montant_estime')->whereHas('pta', fn (Builder $pta): Builder => $pta->where('direction_id', $scopeId))->sum('montant_estime')
                : null,
            default => null,
        };
    }
}
