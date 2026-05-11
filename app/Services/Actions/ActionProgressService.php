<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\ActionPerformanceService;
use Illuminate\Support\Carbon;

class ActionProgressService
{
    public function __construct(
        private readonly ActionPerformanceService $actionPerformanceService
    ) {
    }

    /**
     * @return array<string, float|string|int|bool>
     */
    public function compute(Action $action, ?Carbon $referenceDate = null): array
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $action->loadMissing('sousActions.justificatifs', 'weeks');

        $target = max(0.0, (float) ($action->quantite_cible ?? 0));
        $sousActions = $action->sousActions;
        $realizedQuantity = $this->actionPerformanceService->realizedQuantity($action);
        $totalSousActions = $sousActions->count();
        $completedSousActions = $sousActions
            ->filter(fn (SousAction $sousAction): bool => $this->actionPerformanceService->isCompletedSubAction($sousAction))
            ->count();

        $avancementOperationnel = $totalSousActions > 0
            ? $this->actionPerformanceService->boundRate(($completedSousActions / $totalSousActions) * 100)
            : 0.0;

        $tauxAtteinteCible = $target > 0
            ? $this->actionPerformanceService->boundRate(($realizedQuantity / $target) * 100)
            : 0.0;
        $overachievementRate = $target > 0 && $realizedQuantity > $target
            ? round((($realizedQuantity - $target) / $target) * 100, 2)
            : 0.0;
        $remainingValue = round(max($target - $realizedQuantity, 0.0), 4);

        $progressionReelle = $target > 0
            ? $tauxAtteinteCible
            : ($totalSousActions > 0 ? $avancementOperationnel : 0.0);

        $progressionTheorique = $this->calculateTheoreticalProgress($action, $referenceDate->copy()->endOfDay());

        return [
            'mode_evaluation' => $action->resolvedEvaluationMode(),
            'total_sous_actions' => $totalSousActions,
            'sous_actions_realisees' => $completedSousActions,
            'quantite_realisee' => $realizedQuantity,
            'cible_mesurable_attendue' => $target,
            'reste_a_realiser' => $remainingValue,
            'taux_depassement' => $overachievementRate,
            'avancement_operationnel' => $avancementOperationnel,
            'taux_atteinte_cible' => $tauxAtteinteCible,
            'taux_global' => $progressionReelle,
            'progression_reelle' => $progressionReelle,
            'progression_theorique' => $progressionTheorique,
            'has_sub_tasks' => $totalSousActions > 0,
            'has_quantitative_target' => $target > 0,
        ];
    }

    public function isCompletedSubTask(SousAction $sousAction): bool
    {
        return $this->actionPerformanceService->isCompletedSubAction($sousAction);
    }

    private function calculateTheoreticalProgress(Action $action, Carbon $at): float
    {
        if ($action->date_debut === null || $action->date_fin === null) {
            return 0.0;
        }

        $start = Carbon::parse($action->date_debut)->startOfDay();
        $end = Carbon::parse($action->date_fin)->endOfDay();

        if ($at->lt($start)) {
            return 0.0;
        }

        if ($at->gte($end)) {
            return 100.0;
        }

        $totalDuration = max(1, $start->diffInSeconds($end));
        $elapsed = max(0, $start->diffInSeconds($at));

        return round(min(100.0, ($elapsed / $totalDuration) * 100), 2);
    }

}
