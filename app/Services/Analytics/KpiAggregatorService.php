<?php

namespace App\Services\Analytics;

use App\Models\Action;
use Illuminate\Support\Collection;

class KpiAggregatorService
{
    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float>
     */
    public function summarizeActions(Collection $actions): array
    {
        $average = static function (Collection $items, callable $callback): float {
            if ($items->isEmpty()) {
                return 0.0;
            }

            return round((float) $items->avg($callback), 2);
        };

        return [
            'delai' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
            'performance' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
            'conformite' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)),
            'global' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            'progression' => $average($actions, fn (Action $action): float => (float) ($action->progression_reelle ?? 0)),
        ];
    }
}
