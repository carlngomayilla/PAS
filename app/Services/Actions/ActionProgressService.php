<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\ActionPerformanceService;
use Illuminate\Support\Carbon;

/**
 * Service de calcul de la progression d'une action.
 *
 * Calcule la progression réelle (basée sur les réalisations) et la progression
 * théorique (basée sur le temps écoulé) d'une action à une date de référence donnée.
 *
 * Deux modes de calcul :
 * - Quantitatif : progression = quantité réalisée / quantité cible (en %)
 * - Sous-actions : progression = nombre de sous-actions terminées / total (en %)
 */
class ActionProgressService
{
    public function __construct(
        private readonly ActionPerformanceService $actionPerformanceService
    ) {
    }

    /**
     * Calcule tous les indicateurs de progression d'une action.
     *
     * Retourne un tableau avec : progression réelle, progression théorique,
     * quantité réalisée, reste à réaliser, taux d'atteinte de la cible, etc.
     *
     * @return array<string, float|string|int|bool>
     */
    public function compute(Action $action, ?Carbon $referenceDate = null): array
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        if ($action->exists) {
            $action->load('sousActions.justificatifs');
        } else {
            $action->loadMissing('sousActions.justificatifs');
        }

        $target = $action->usesQuantitativeProgress()
            ? max(0.0, (float) ($action->quantite_cible ?? 0))
            : 0.0;
        $sousActions = $action->sousActions;
        $realizedQuantity = $this->actionPerformanceService->realizedQuantity($action);
        $totalSousActions = $sousActions->count();
        $completedSousActions = $sousActions
            ->filter(fn (SousAction $sousAction): bool => $this->actionPerformanceService->isValidatedSubAction($sousAction))
            ->count();
        $declaredSousActions = $sousActions
            ->filter(fn (SousAction $sousAction): bool => $this->actionPerformanceService->isCompletedSubAction($sousAction))
            ->count();

        $avancementOperationnel = $totalSousActions > 0
            ? $this->actionPerformanceService->boundRate(($completedSousActions / $totalSousActions) * 100)
            : 0.0;
        $avancementDeclare = $this->actionPerformanceService->calculateDeclaredProgress($action);

        $tauxAtteinteCible = $target > 0
            ? $this->actionPerformanceService->boundRate(($realizedQuantity / $target) * 100)
            : 0.0;
        $overachievementRate = $target > 0 && $realizedQuantity > $target
            ? round((($realizedQuantity - $target) / $target) * 100, 2)
            : 0.0;
        $remainingValue = round(max($target - $realizedQuantity, 0.0), 4);

        $progressionReelle = $target > 0
            ? $tauxAtteinteCible
            : ($totalSousActions > 0
                ? $avancementOperationnel
                : ($action->usesNoQuantityProgress() ? $avancementDeclare : 0.0));

        $progressionTheorique = $this->calculateTheoreticalProgress($action, $referenceDate->copy()->endOfDay());

        return [
            'mode_evaluation' => $action->resolvedEvaluationMode(),
            'total_sous_actions' => $totalSousActions,
            'sous_actions_realisees' => $completedSousActions,
            'sous_actions_declarees' => $declaredSousActions,
            'quantite_realisee' => $realizedQuantity,
            'cible_mesurable_attendue' => $target,
            'reste_a_realiser' => $remainingValue,
            'taux_depassement' => $overachievementRate,
            'avancement_operationnel' => $avancementOperationnel,
            'progression_declaree' => $avancementDeclare,
            'taux_atteinte_cible' => $tauxAtteinteCible,
            'taux_global' => $progressionReelle,
            'progression_reelle' => $progressionReelle,
            'progression_theorique' => $progressionTheorique,
            'has_sub_tasks' => $totalSousActions > 0,
            'has_quantitative_target' => $target > 0,
        ];
    }

    /** Indique si une sous-action est considérée comme terminée. */
    public function isCompletedSubTask(SousAction $sousAction): bool
    {
        return $this->actionPerformanceService->isCompletedSubAction($sousAction);
    }

    /**
     * Calcule la progression théorique de l'action à une date donnée.
     * C'est le pourcentage du temps écoulé entre la date de début et la date de fin prévue.
     * Exemple : une action de 10 jours, à J+5 → progression théorique = 50%.
     */
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
