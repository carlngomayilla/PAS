<?php

namespace App\Services\Ai;

use App\Models\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActionReportMetricsBuilder
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(string $scope, array $filters = []): array
    {
        $query = Action::query()->with(['pta.direction', 'pta.service', 'pao.direction']);
        $this->applyFilters($query, $filters);
        $this->applyScope($query, $scope);

        /** @var Collection<int, Action> $actions */
        $actions = $query->get();
        $today = now()->startOfDay();
        $late = $actions->filter(fn (Action $action): bool => $this->isLate($action, $today));
        $closed = $actions->filter(fn (Action $action): bool => $this->isClosed($action));
        $running = $actions->filter(fn (Action $action): bool => $this->isRunning($action));

        return [
            'source' => 'laravel_database',
            'scope' => $scope,
            'generated_at' => now()->toIso8601String(),
            'filters' => $filters,
            'totaux' => [
                'actions' => $actions->count(),
                'actions_en_cours' => $running->count(),
                'actions_cloturees' => $closed->count(),
                'actions_hors_delai' => $late->count(),
                'budget_previsionnel' => round((float) $actions->sum(fn (Action $action): float => (float) ($action->montant_estime ?? 0)), 2),
                'progression_moyenne' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
            ],
            'par_statut' => $actions
                ->groupBy(fn (Action $action): string => (string) ($action->statut_dynamique ?: $action->statut ?: 'non_renseigne'))
                ->map->count()
                ->sortKeys()
                ->all(),
            'par_direction' => $this->countBy($actions, fn (Action $action): string => (string) ($action->pta?->direction?->libelle ?? $action->pao?->direction?->libelle ?? 'Non renseignee')),
            'par_service' => $this->countBy($actions, fn (Action $action): string => (string) ($action->pta?->service?->libelle ?? 'Non renseigne')),
            'actions_hors_delai' => $late->take(20)->map(fn (Action $action): array => $this->actionLine($action))->values()->all(),
            'actions_critiques' => $late->sortBy('date_fin')->take(10)->map(fn (Action $action): array => $this->actionLine($action))->values()->all(),
            'alertes' => $late->take(10)->map(fn (Action $action): string => 'Action hors delai : '.(string) $action->libelle)->values()->all(),
            'difficultes' => [],
            'recommandations_metier' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['period_start'])) {
            $query->whereDate('date_fin', '>=', (string) $filters['period_start']);
        }

        if (! empty($filters['period_end'])) {
            $query->whereDate('date_fin', '<=', (string) $filters['period_end']);
        }

        if (! empty($filters['direction_id'])) {
            $directionId = (int) $filters['direction_id'];
            $query->where(function (Builder $builder) use ($directionId): void {
                $builder->whereHas('pta', fn (Builder $pta): Builder => $pta->where('direction_id', $directionId))
                    ->orWhereHas('pao', fn (Builder $pao): Builder => $pao->where('direction_id', $directionId));
            });
        }

        if (! empty($filters['service_id'])) {
            $query->whereHas('pta', fn (Builder $pta): Builder => $pta->where('service_id', (int) $filters['service_id']));
        }
    }

    private function applyScope(Builder $query, string $scope): void
    {
        match ($scope) {
            'pta' => $query->whereNotNull('pta_id'),
            'pao' => $query->whereNotNull('pao_id'),
            'late_actions' => $query->whereDate('date_fin', '<', now()->toDateString())
                ->whereNotIn('statut', ['termine', 'annule']),
            'running_actions' => $query->where('statut', 'en_cours'),
            'closed_actions' => $query->where(function (Builder $builder): void {
                $builder->where('statut', 'termine')->orWhere('statut_dynamique', 'cloturee');
            }),
            default => null,
        };
    }

    private function isLate(Action $action, Carbon $today): bool
    {
        return $action->date_fin !== null
            && $action->date_fin->lt($today)
            && ! $this->isClosed($action)
            && (string) $action->statut !== 'annule';
    }

    private function isClosed(Action $action): bool
    {
        return in_array((string) $action->statut, ['termine'], true)
            || in_array((string) $action->statut_dynamique, ['cloturee'], true);
    }

    private function isRunning(Action $action): bool
    {
        return in_array((string) $action->statut, ['en_cours', 'non_demarre'], true)
            && ! $this->isClosed($action);
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @param  callable(Action): string  $callback
     * @return array<string, int>
     */
    private function countBy(Collection $actions, callable $callback): array
    {
        return $actions->groupBy($callback)->map->count()->sortKeys()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function actionLine(Action $action): array
    {
        return [
            'code' => $action->code,
            'libelle' => $action->libelle,
            'statut' => $action->statut_dynamique ?: $action->statut,
            'date_fin' => $action->date_fin?->toDateString(),
            'direction' => $action->pta?->direction?->libelle ?? $action->pao?->direction?->libelle,
            'service' => $action->pta?->service?->libelle,
        ];
    }
}
