<?php

namespace App\Services\Analytics;

use App\Models\Action;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActionSummaryService
{
    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float|int>
     */
    public function summarize(Collection $actions): array
    {
        if ($actions->isEmpty()) {
            return $this->emptySummary();
        }

        $total = $actions->count();
        $completed = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_dynamique, $this->completedStatuses(), true))
            ->count();
        $delayed = $actions
            ->filter(fn (Action $action): bool => $this->isDelayed($action))
            ->count();

        return [
            'actions_total' => $total,
            'actions_validees' => $actions
                ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                    ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                    ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                ], true))
                ->count(),
            'actions_terminees' => $completed,
            'actions_en_cours' => max(0, $total - $completed - $delayed),
            'actions_retard' => $delayed,
            'progression_moyenne' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
            'taux_realisation' => $this->rate($completed, $total),
            'taux_retard' => $this->rate($delayed, $total),
            'kpi_global' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
            'kpi_conformite' => round((float) $actions->avg(fn (Action $action): float => 0.0), 2),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function emptySummary(): array
    {
        return [
            'actions_total' => 0,
            'actions_validees' => 0,
            'actions_terminees' => 0,
            'actions_en_cours' => 0,
            'actions_retard' => 0,
            'progression_moyenne' => 0.0,
            'taux_realisation' => 0.0,
            'taux_retard' => 0.0,
            'kpi_global' => 0.0,
            'kpi_conformite' => 0.0,
        ];
    }

    public function rate(int $done, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($done / $total) * 100, 2);
    }

    private function isDelayed(Action $action): bool
    {
        if ((string) $action->statut_dynamique === ActionTrackingService::STATUS_EN_RETARD) {
            return true;
        }

        if ($action->date_fin === null || (float) ($action->progression_reelle ?? 0) >= 100.0) {
            return false;
        }

        return $action->date_fin->lt(Carbon::today());
    }

    /**
     * @return list<string>
     */
    private function completedStatuses(): array
    {
        return ActionTrackingService::completedActionStatuses();
    }
}