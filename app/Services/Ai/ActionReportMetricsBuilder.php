<?php

namespace App\Services\Ai;

use App\Models\Action;
use App\Services\Actions\ActionStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActionReportMetricsBuilder
{
    public function __construct(
        private readonly ActionStatusService $actionStatusService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(string $scope, array $filters = []): array
    {
        $query = Action::query()->with([
            'objectifOperationnel',
            'pao.direction',
            'pta.direction',
            'pta.service',
            'pta.objectifOperationnel',
            'pta.pao.pasObjectif.pasAxe',
            'responsable',
        ]);
        $this->applyFilters($query, $filters);
        $this->applyScope($query, $scope);

        /** @var Collection<int, Action> $actions */
        $actions = $query->get();
        $today = now()->startOfDay();
        $late = $actions->filter(fn (Action $action): bool => $this->isLate($action, $today));
        $closed = $actions->filter(fn (Action $action): bool => $this->isClosed($action));
        $running = $actions->filter(fn (Action $action): bool => $this->isRunning($action));

        $payload = [
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

        if ($scope === 'pta') {
            $payload['pta_analyse'] = $this->buildPtaQuarterlyAnalysis($actions, $filters);
        }

        return $payload;
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
            'axe' => $this->axisLabel($action),
            'objectif_strategique' => $this->strategicObjectiveLabel($action),
            'objectif_operationnel' => $this->operationalObjectiveLabel($action),
            'responsable' => $action->responsable?->name,
            'progression' => $this->progressRate($action),
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildPtaQuarterlyAnalysis(Collection $actions, array $filters): array
    {
        $periodEnd = $this->resolvePeriodEnd($actions, $filters);
        $periodStart = $this->resolvePeriodStart($actions, $filters, $periodEnd);

        $axes = $actions
            ->groupBy(fn (Action $action): string => $this->axisKey($action))
            ->map(fn (Collection $rows): array => $this->analysisRow($rows, $periodEnd) + [
                'code' => (string) ($rows->first()?->pta?->pao?->pasObjectif?->pasAxe?->code ?? ''),
                'libelle' => $this->axisLabel($rows->first()),
            ])
            ->sortBy('libelle')
            ->values()
            ->all();

        $services = $actions
            ->groupBy(fn (Action $action): string => $this->serviceKey($action))
            ->map(fn (Collection $rows): array => $this->analysisRow($rows, $periodEnd) + [
                'direction' => (string) ($rows->first()?->pta?->direction?->libelle ?? $rows->first()?->pao?->direction?->libelle ?? 'Non renseignee'),
                'libelle' => (string) ($rows->first()?->pta?->service?->libelle ?? 'Non renseigne'),
            ])
            ->sortBy('libelle')
            ->values()
            ->all();

        $monthly = $this->monthlyEvolution($actions, $periodStart, $periodEnd);
        $lateOrUnrealized = $this->dueActions($actions, $periodEnd)
            ->reject(fn (Action $action): bool => $this->isCompleted($action))
            ->values();
        $partial = $this->dueActions($actions, $periodEnd)
            ->filter(fn (Action $action): bool => ! $this->isCompleted($action) && $this->progressRate($action) > 0)
            ->values();
        $postponed = $actions
            ->filter(fn (Action $action): bool => $action->date_fin !== null && $action->date_fin->gt($periodEnd))
            ->values();

        return [
            'periode' => [
                'debut' => $periodStart->toDateString(),
                'fin' => $periodEnd->toDateString(),
                'libelle' => $periodStart->translatedFormat('F Y').' - '.$periodEnd->translatedFormat('F Y'),
            ],
            'synthese' => $this->analysisRow($actions, $periodEnd),
            'axes' => $axes,
            'services' => $services,
            'evolution_mensuelle' => $monthly,
            'ecarts' => [
                'actions_non_realisees' => $lateOrUnrealized->take(15)->map(fn (Action $action): array => $this->actionLine($action))->all(),
                'actions_partielles' => $partial->take(15)->map(fn (Action $action): array => $this->actionLine($action))->all(),
                'actions_reportees' => $postponed->take(15)->map(fn (Action $action): array => $this->actionLine($action))->all(),
            ],
            'mesures_correctives' => $this->correctiveMeasures($lateOrUnrealized, $partial, $postponed),
            'graphiques' => [
                'taux_axes' => [
                    'labels' => collect($axes)->pluck('libelle')->values()->all(),
                    'values' => collect($axes)->pluck('taux_realisation')->values()->all(),
                ],
                'taux_services' => [
                    'labels' => collect($services)->pluck('libelle')->values()->all(),
                    'values' => collect($services)->pluck('taux_realisation')->values()->all(),
                ],
                'evolution_trimestre' => [
                    'labels' => collect($monthly)->pluck('mois')->values()->all(),
                    'values' => collect($monthly)->pluck('taux_realisation')->values()->all(),
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array<string, int|float>
     */
    private function analysisRow(Collection $actions, Carbon $periodEnd): array
    {
        $due = $this->dueActions($actions, $periodEnd);
        $completed = $actions->filter(fn (Action $action): bool => $this->isCompleted($action));
        $dueCompleted = $due->filter(fn (Action $action): bool => $this->isCompleted($action));
        $dueUnrealized = $due->reject(fn (Action $action): bool => $this->isCompleted($action));
        $notStarted = $actions->filter(fn (Action $action): bool => $this->dashboardStatus($action) === 'non_demarre');

        return [
            'actions_prevues' => $actions->count(),
            'actions_realisees' => $completed->count(),
            'actions_en_retard_non_realisees' => $dueUnrealized->count(),
            'actions_non_demarrees' => $notStarted->count(),
            'actions_echues' => $due->count(),
            'taux_global_avancement' => $this->rate($completed->count(), $actions->count()),
            'taux_realisation' => $this->rate($dueCompleted->count(), $due->count()),
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return Collection<int, Action>
     */
    private function dueActions(Collection $actions, Carbon $periodEnd): Collection
    {
        return $actions->filter(
            fn (Action $action): bool => $action->date_fin !== null && $action->date_fin->lte($periodEnd)
        );
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return list<array<string, int|float|string>>
     */
    private function monthlyEvolution(Collection $actions, Carbon $periodStart, Carbon $periodEnd): array
    {
        $months = [];
        $cursor = $periodStart->copy()->startOfMonth();
        $last = $periodEnd->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            if ($monthEnd->gt($periodEnd)) {
                $monthEnd = $periodEnd->copy();
            }
            $monthActions = $actions->filter(
                fn (Action $action): bool => $action->date_fin !== null
                    && $action->date_fin->betweenIncluded($monthStart, $monthEnd)
            );
            $completed = $monthActions->filter(fn (Action $action): bool => $this->isCompleted($action))->count();

            $months[] = [
                'mois' => $cursor->translatedFormat('M Y'),
                'actions_echues' => $monthActions->count(),
                'actions_realisees' => $completed,
                'taux_realisation' => $this->rate($completed, $monthActions->count()),
            ];

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @param  Collection<int, Action>  $lateOrUnrealized
     * @param  Collection<int, Action>  $partial
     * @param  Collection<int, Action>  $postponed
     * @return list<string>
     */
    private function correctiveMeasures(Collection $lateOrUnrealized, Collection $partial, Collection $postponed): array
    {
        $measures = [
            'Planifier les actions PTA avant leur date d echeance et formaliser le calendrier de suivi.',
            'Relancer les responsables de mise en oeuvre sur les actions echues non realisees.',
        ];

        if ($partial->isNotEmpty()) {
            $measures[] = 'Identifier les blocages des actions partiellement realisees et fixer un delai court de regularisation.';
        }

        if ($postponed->isNotEmpty()) {
            $measures[] = 'Arbitrer les actions reportees et confirmer leur nouvelle periode de realisation.';
        }

        if ($lateOrUnrealized->count() > 5) {
            $measures[] = 'Organiser une revue hebdomadaire des ecarts jusqu au retour au taux cible.';
        }

        return $measures;
    }

    private function resolvePeriodEnd(Collection $actions, array $filters): Carbon
    {
        if (! empty($filters['period_end'])) {
            return Carbon::parse((string) $filters['period_end'])->endOfDay();
        }

        $latest = $actions
            ->pluck('date_fin')
            ->filter()
            ->sort()
            ->last();

        return $latest instanceof Carbon ? $latest->copy()->endOfDay() : now()->endOfDay();
    }

    private function resolvePeriodStart(Collection $actions, array $filters, Carbon $periodEnd): Carbon
    {
        if (! empty($filters['period_start'])) {
            return Carbon::parse((string) $filters['period_start'])->startOfDay();
        }

        $earliest = $actions
            ->pluck('date_fin')
            ->filter()
            ->sort()
            ->first();

        if ($earliest instanceof Carbon) {
            return $earliest->copy()->startOfMonth()->startOfDay();
        }

        return $periodEnd->copy()->subMonths(2)->startOfMonth()->startOfDay();
    }

    private function dashboardStatus(Action $action): string
    {
        return $this->actionStatusService->dashboardStatus($action);
    }

    private function isCompleted(Action $action): bool
    {
        return $this->dashboardStatus($action) === 'acheve';
    }

    private function progressRate(Action $action): float
    {
        foreach (['progression_reelle', 'taux_global', 'taux_realisation_global', 'avancement_operationnel', 'taux_atteinte_cible'] as $field) {
            $value = (float) ($action->{$field} ?? 0);
            if ($value > 0) {
                return round(min(100, $value), 2);
            }
        }

        return $this->isCompleted($action) ? 100.0 : 0.0;
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function axisKey(?Action $action): string
    {
        return (string) ($action?->pta?->pao?->pasObjectif?->pasAxe?->id ?? 'sans_axe');
    }

    private function axisLabel(?Action $action): string
    {
        return (string) ($action?->pta?->pao?->pasObjectif?->pasAxe?->libelle ?? 'Sans axe strategique');
    }

    private function serviceKey(Action $action): string
    {
        return (string) ($action->pta?->service?->id ?? 'sans_service');
    }

    private function strategicObjectiveLabel(Action $action): string
    {
        return (string) ($action->pta?->pao?->pasObjectif?->libelle ?? 'Non renseigne');
    }

    private function operationalObjectiveLabel(Action $action): string
    {
        return (string) (
            $action->objectifOperationnel?->libelle
            ?? $action->pta?->objectifOperationnel?->libelle
            ?? $action->pta?->pao?->objectif_operationnel
            ?? 'Non renseigne'
        );
    }
}
