<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\SousAction;
use Illuminate\Support\Carbon;

class ActionProgressService
{
    /**
     * @return array<string, float|string|int|bool>
     */
    public function compute(Action $action, ?Carbon $referenceDate = null): array
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $action->loadMissing('sousActions.justificatifs', 'weeks');

        $mode = $action->resolvedEvaluationMode();
        $target = max(0.0, (float) ($action->quantite_cible ?? 0));
        $sousActions = $action->sousActions;
        $realizedFromSubActions = $sousActions->sum(fn (SousAction $sousAction): float => max(0.0, (float) ($sousAction->quantite_realisee ?? 0)));
        $storedRealizedQuantity = max(0.0, (float) ($action->quantite_realisee ?? 0));
        $weeklyRealizedQuantity = $action->weeks
            ->filter(fn ($week): bool => (bool) $week->est_renseignee)
            ->sum(fn ($week): float => max(0.0, (float) ($week->quantite_realisee ?? 0)));
        $realizedQuantity = $sousActions->isNotEmpty()
            ? $realizedFromSubActions
            : ($storedRealizedQuantity > 0.0
                ? $storedRealizedQuantity
                : max(0.0, (float) $weeklyRealizedQuantity));
        $totalSousActions = $sousActions->count();
        $completedSousActions = $sousActions->where('est_effectuee', true)->count();

        $avancementOperationnel = $totalSousActions > 0
            ? round(($completedSousActions / $totalSousActions) * 100, 2)
            : 0.0;

        $tauxAtteinteCible = $target > 0
            ? round(min(100.0, ($realizedQuantity / $target) * 100), 2)
            : 0.0;
        $overachievementRate = $target > 0 && $realizedQuantity > $target
            ? round((($realizedQuantity - $target) / $target) * 100, 2)
            : 0.0;
        $remainingValue = round(max($target - $realizedQuantity, 0.0), 4);

        $progressionReelle = match ($mode) {
            Action::MODE_QUANTITATIF => $tauxAtteinteCible,
            Action::MODE_MIXTE => round(($avancementOperationnel + $tauxAtteinteCible) / 2, 2),
            default => $avancementOperationnel,
        };

        $progressionTheorique = $this->calculateTheoreticalProgress($action, $referenceDate->copy()->endOfDay());

        return [
            'mode_evaluation' => $mode,
            'total_sous_actions' => $totalSousActions,
            'sous_actions_realisees' => $completedSousActions,
            'quantite_realisee' => $realizedQuantity,
            'cible_mesurable_attendue' => $target,
            'reste_a_realiser' => $remainingValue,
            'taux_depassement' => $overachievementRate,
            'avancement_operationnel' => $avancementOperationnel,
            'taux_atteinte_cible' => $tauxAtteinteCible,
            'taux_global' => match ($mode) {
                Action::MODE_MIXTE => round(($avancementOperationnel + $tauxAtteinteCible) / 2, 2),
                Action::MODE_QUANTITATIF => $tauxAtteinteCible,
                default => $avancementOperationnel,
            },
            'progression_reelle' => $progressionReelle,
            'progression_theorique' => $progressionTheorique,
            'has_sub_tasks' => $totalSousActions > 0,
            'has_quantitative_target' => $target > 0,
        ];
    }

    public function isCompletedSubTask(SousAction $sousAction): bool
    {
        return (bool) $sousAction->est_effectuee
            && trim((string) ($sousAction->commentaire ?? '')) !== ''
            && $sousAction->justificatifs->isNotEmpty();
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
