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
     * Build the canonical quarterly PTA analysis from an already authorized action collection.
     *
     * @param  Collection<int, Action>  $actions
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildPtaAnalysis(Collection $actions, array $filters = []): array
    {
        return $this->buildPtaQuarterlyAnalysis($actions->values(), $filters);
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

        if (! empty($filters['responsable_id'])) {
            $query->where('responsable_id', (int) $filters['responsable_id']);
        }

        if (! empty($filters['statut'])) {
            $status = (string) $filters['statut'];
            $query->where(function (Builder $builder) use ($status): void {
                $builder->where('statut', $status)->orWhere('statut_dynamique', $status);
            });
        }

        if (! empty($filters['pas_axe_id'])) {
            $axisId = (int) $filters['pas_axe_id'];
            $query->where(function (Builder $builder) use ($axisId): void {
                $builder->whereHas('objectifOperationnel', fn (Builder $objective): Builder => $objective->where('pas_axe_id', $axisId))
                    ->orWhereHas('pta.objectifOperationnel', fn (Builder $objective): Builder => $objective->where('pas_axe_id', $axisId))
                    ->orWhereHas('pta.pao.pasObjectif', fn (Builder $objective): Builder => $objective->where('pas_axe_id', $axisId));
            });
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
        $axisMonthly = $this->axisMonthlyEvolution($actions, $periodStart, $periodEnd);
        $serviceAxisMatrix = $this->serviceAxisMatrix($actions, $axes, $periodEnd);
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
            'matrice_services_axes' => $serviceAxisMatrix,
            'evolution_mensuelle' => $monthly,
            'evolution_mensuelle_axes' => $axisMonthly,
            'comparaison_indicateurs' => $this->indicatorComparison($actions, $periodEnd),
            'ecarts' => [
                'actions_non_realisees' => $lateOrUnrealized->take(15)->map(fn (Action $action): array => $this->actionLine($action))->all(),
                'actions_partielles' => $partial->take(15)->map(fn (Action $action): array => $this->actionLine($action))->all(),
                'actions_reportees' => $postponed->take(15)->map(fn (Action $action): array => $this->actionLine($action))->all(),
            ],
            'mesures_correctives' => $this->correctiveMeasures($lateOrUnrealized, $partial, $postponed),
            'constats' => $this->analysisFindings($axes, $services, $lateOrUnrealized),
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
                'progression_axes' => [
                    'labels' => collect($axisMonthly)->pluck('axe')->values()->all(),
                    'series' => $this->axisMonthlySeries($axisMonthly, $monthly),
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @param  list<array<string, mixed>>  $axes
     * @return list<array<string, mixed>>
     */
    private function serviceAxisMatrix(Collection $actions, array $axes, Carbon $periodEnd): array
    {
        $axisLabels = collect($axes)
            ->map(fn (array $axis): string => (string) ($axis['libelle'] ?? 'Sans axe strategique'))
            ->unique()
            ->values();

        if ($axisLabels->isEmpty()) {
            return [];
        }

        return $actions
            ->groupBy(fn (Action $action): string => $this->serviceKey($action))
            ->map(function (Collection $rows) use ($axisLabels, $periodEnd): array {
                $axisCells = [];

                foreach ($axisLabels as $axisLabel) {
                    $axisRows = $rows
                        ->filter(fn (Action $action): bool => $this->axisLabel($action) === $axisLabel)
                        ->values();
                    $analysis = $this->analysisRow($axisRows, $periodEnd);

                    $axisCells[$axisLabel] = [
                        'actions_prevues' => $analysis['actions_prevues'],
                        'actions_echues' => $analysis['actions_echues'],
                        'taux_realisation' => $analysis['taux_realisation'],
                        'poids' => $analysis['actions_echues'].'/'.$analysis['actions_prevues'],
                    ];
                }

                $serviceAnalysis = $this->analysisRow($rows, $periodEnd);

                return [
                    'direction' => (string) ($rows->first()?->pta?->direction?->libelle ?? $rows->first()?->pao?->direction?->libelle ?? 'Non renseignee'),
                    'service' => (string) ($rows->first()?->pta?->service?->libelle ?? 'Non renseigne'),
                    'taux_realisation' => $serviceAnalysis['taux_realisation'],
                    'actions_echues' => $serviceAnalysis['actions_echues'],
                    'axes' => $axisCells,
                ];
            })
            ->sortBy('service')
            ->values()
            ->all();
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
        $inProgress = $actions->filter(fn (Action $action): bool => $this->dashboardStatus($action) === 'en_cours');
        $progressRate = $this->rate($completed->count(), $actions->count());
        $realizationRate = $this->rate($dueCompleted->count(), $due->count());

        return [
            'actions_prevues' => $actions->count(),
            'actions_realisees' => $completed->count(),
            'actions_echues_realisees' => $dueCompleted->count(),
            'actions_en_retard_non_realisees' => $dueUnrealized->count(),
            'actions_non_demarrees' => $notStarted->count(),
            'actions_en_cours' => $inProgress->count(),
            'actions_echues' => $due->count(),
            'taux_global_avancement' => $progressRate,
            'taux_realisation' => $realizationRate,
            'niveau_performance' => $this->performanceLevel($realizationRate),
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
        $previousRate = null;

        while ($cursor->lte($last)) {
            $monthEnd = $cursor->copy()->endOfMonth();
            if ($monthEnd->gt($periodEnd)) {
                $monthEnd = $periodEnd->copy();
            }
            $monthActions = $this->dueActions($actions, $monthEnd);
            $completed = $monthActions->filter(fn (Action $action): bool => $this->wasCompletedAt($action, $monthEnd))->count();
            $rate = $this->rate($completed, $monthActions->count());
            $variation = $previousRate === null ? 0.0 : round($rate - $previousRate, 2);

            $months[] = [
                'mois' => $cursor->translatedFormat('M Y'),
                'actions_echues' => $monthActions->count(),
                'actions_realisees' => $completed,
                'taux_realisation' => $rate,
                'variation' => $variation,
                'tendance' => $variation > 0 ? 'Hausse' : ($variation < 0 ? 'Baisse' : 'Stagnation'),
            ];

            $previousRate = $rate;
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return list<array<string, mixed>>
     */
    private function axisMonthlyEvolution(Collection $actions, Carbon $periodStart, Carbon $periodEnd): array
    {
        $months = [];
        $cursor = $periodStart->copy()->startOfMonth();

        while ($cursor->lte($periodEnd->copy()->startOfMonth())) {
            $months[] = [
                'label' => $cursor->translatedFormat('M Y'),
                'end' => $cursor->copy()->endOfMonth()->min($periodEnd),
            ];
            $cursor->addMonth();
        }

        return $actions
            ->groupBy(fn (Action $action): string => $this->axisKey($action))
            ->map(function (Collection $rows) use ($months): array {
                $rates = collect($months)->map(function (array $month) use ($rows): array {
                    /** @var Carbon $monthEnd */
                    $monthEnd = $month['end'];
                    $due = $this->dueActions($rows, $monthEnd);
                    $completed = $due->filter(fn (Action $action): bool => $this->wasCompletedAt($action, $monthEnd))->count();

                    return [
                        'mois' => (string) $month['label'],
                        'taux' => $this->rate($completed, $due->count()),
                    ];
                })->all();

                return [
                    'code' => (string) ($rows->first()?->pta?->pao?->pasObjectif?->pasAxe?->code ?? ''),
                    'axe' => $this->axisLabel($rows->first()),
                    'mois' => $rates,
                    'evolution' => round((float) (collect($rates)->last()['taux'] ?? 0) - (float) (collect($rates)->first()['taux'] ?? 0), 2),
                ];
            })
            ->sortBy('axe')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function indicatorComparison(Collection $actions, Carbon $periodEnd): array
    {
        $row = $this->analysisRow($actions, $periodEnd);

        return [
            [
                'indicateur' => 'Avancement global du PTA',
                'realisees' => $row['actions_realisees'],
                'base' => $row['actions_prevues'],
                'taux' => $row['taux_global_avancement'],
                'formule' => 'Actions realisees / actions prevues x 100',
                'interpretation' => 'Niveau global d execution du PTA',
            ],
            [
                'indicateur' => 'Realisation des actions echues',
                'realisees' => $row['actions_echues_realisees'],
                'base' => $row['actions_echues'],
                'taux' => $row['taux_realisation'],
                'formule' => 'Actions echues realisees / actions echues x 100',
                'interpretation' => 'Respect des echeances arrivees a terme',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $axisMonthly
     * @param  list<array<string, mixed>>  $monthly
     * @return list<array<string, mixed>>
     */
    private function axisMonthlySeries(array $axisMonthly, array $monthly): array
    {
        return collect($monthly)->map(function (array $month, int $monthIndex) use ($axisMonthly): array {
            return [
                'label' => (string) ($month['mois'] ?? ''),
                'values' => collect($axisMonthly)->map(
                    fn (array $axis): float => round((float) ($axis['mois'][$monthIndex]['taux'] ?? 0), 2)
                )->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<string, list<string>> */
    private function analysisFindings(array $axes, array $services, Collection $unrealized): array
    {
        $axisRows = collect($axes)->sortByDesc('taux_realisation')->values();
        $serviceRows = collect($services)->sortByDesc('taux_realisation')->values();
        $strongestAxis = $axisRows->first();
        $weakestAxis = $axisRows->last();
        $strongestService = $serviceRows->first();
        $weakestService = $serviceRows->last();

        return [
            'points_forts' => array_values(array_filter([
                $strongestAxis ? 'Axe le plus avance : '.($strongestAxis['libelle'] ?? 'Non renseigne').' ('.$strongestAxis['taux_realisation'].' %).' : null,
                $strongestService ? 'Service le plus avance : '.($strongestService['libelle'] ?? 'Non renseigne').' ('.$strongestService['taux_realisation'].' %).' : null,
            ])),
            'points_faibles' => array_values(array_filter([
                $weakestAxis ? 'Axe necessitant le plus d attention : '.($weakestAxis['libelle'] ?? 'Non renseigne').' ('.$weakestAxis['taux_realisation'].' %).' : null,
                $weakestService ? 'Service necessitant un accompagnement : '.($weakestService['libelle'] ?? 'Non renseigne').' ('.$weakestService['taux_realisation'].' %).' : null,
                $unrealized->isNotEmpty() ? $unrealized->count().' action(s) echue(s) restent non realisee(s).' : null,
            ])),
            'priorites' => array_values(array_filter([
                $unrealized->isNotEmpty() ? 'Traiter en priorite les actions echues non realisees.' : null,
                collect($axes)->contains(fn (array $axis): bool => (float) ($axis['taux_realisation'] ?? 0) < 60) ? 'Renforcer le suivi des axes dont le taux est inferieur a 60 %.' : null,
            ])),
        ];
    }

    private function wasCompletedAt(Action $action, Carbon $observationEnd): bool
    {
        if (! $this->isCompleted($action)) {
            return false;
        }

        $completedAt = $action->date_fin_reelle ?? $action->cloture_le;

        return $completedAt === null || $completedAt->lte($observationEnd);
    }

    private function performanceLevel(float $rate): string
    {
        return match (true) {
            $rate >= 80 => 'Tres satisfaisant',
            $rate >= 60 => 'Satisfaisant',
            $rate >= 40 => 'Moyen',
            $rate >= 20 => 'Faible',
            default => 'Critique',
        };
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
            $measures[] = 'Organiser une revue hebdomadaire des ecarts jusqu au retour au seuil attendu.';
        }

        return $measures;
    }

    private function resolvePeriodEnd(Collection $actions, array $filters): Carbon
    {
        if (! empty($filters['period_end'])) {
            return Carbon::parse((string) $filters['period_end'])->endOfDay();
        }

        return now()->endOfDay();
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
