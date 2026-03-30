<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Analytics\ReportingAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly ReportingAnalyticsService $reportingAnalyticsService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadDashboard($user)) {
            abort(403, 'Acces non autorise.');
        }
        $user->loadMissing([
            'direction:id,libelle',
            'service:id,libelle',
        ]);

        $today = Carbon::today()->toDateString();

        $pas = $this->buildPasScopedQuery($user);
        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $kpis = Kpi::query();
        $mesures = KpiMesure::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeMesure($mesures, $user);
        $actionsStatistics = (clone $actions)
            ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION);

        $scopedActions = (clone $actions)
            ->with([
                'pta:id,pao_id,titre,direction_id,service_id',
                'pta.pao:id,pas_id,pas_objectif_id,direction_id,annee,titre,statut',
                'pta.pao.pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pta.pao.pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'responsable:id,name',
                'actionKpi:id,action_id,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite,kpi_risque,kpi_global,progression_reelle,progression_theorique',
            ])
            ->orderByDesc('date_echeance')
            ->get();

        $totals = [
            'pas_total' => (clone $pas)->count(),
            'paos_total' => (clone $paos)->count(),
            'ptas_total' => (clone $ptas)->count(),
            'actions_total' => (clone $actions)->count(),
            'actions_validees' => (clone $actionsStatistics)->count(),
            'kpis_total' => (clone $kpis)->count(),
            'kpi_mesures_total' => (clone $mesures)->count(),
        ];

        $statusBreakdown = [
            'paos' => $this->countByStatus($paos, 'statut'),
            'ptas' => $this->countByStatus($ptas, 'statut'),
            'actions' => $this->countByStatus($actions, 'statut_dynamique'),
            'actions_validation' => $this->countByStatus($actions, 'statut_validation'),
        ];

        $actionsRetard = (clone $actions)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', [
                ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
                ActionTrackingService::STATUS_SUSPENDU,
                ActionTrackingService::STATUS_ANNULE,
            ])
            ->count();

        $kpiSousSeuilQuery = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');
        $this->scopeJoinedPta($kpiSousSeuilQuery, $user, 'ptas.direction_id', 'ptas.service_id');

        $alerts = [
            'actions_en_retard' => $actionsRetard,
            'mesures_kpi_sous_seuil' => $kpiSousSeuilQuery->count(),
        ];

        $view = $request->routeIs('admin.*') ? 'admin.dashboard' : 'dashboard';
        $dashboardData = $this->buildDashboardData($user, $scopedActions);
        $reportingAnalytics = $this->reportingAnalyticsService->buildPayload($user, true, true);
        $dgPayload = [
            'global_scores' => $dashboardData['global_scores'] ?? [],
            'alert_rows' => $dashboardData['alert_rows'] ?? [],
            'kpi_summary' => $reportingAnalytics['kpiSummary'] ?? [],
        ];

        return view($view, [
            'user' => $user,
            'profil' => $user->profileInteractions(),
            'modules' => $user->workspaceModules(),
            'metrics' => [
                'totals' => $totals,
                'alerts' => $alerts,
                'status_breakdown' => $statusBreakdown,
            ],
            'dashboardData' => $dashboardData,
            'reportingAnalytics' => $reportingAnalytics,
            'dgPayload' => $dgPayload,
            'chartPayload' => $this->buildChartPayload($totals, $alerts, $statusBreakdown),
        ]);
    }

    private function scopePao(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id');
    }

    private function scopePta(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
    }

    private function scopeAction(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where('responsable_id', (int) $user->id);
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeKpi(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->whereHas('action', fn (Builder $q) => $q->where('responsable_id', (int) $user->id));
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeMesure(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->whereHas('kpi.action', fn (Builder $q) => $q->where('responsable_id', (int) $user->id));
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeJoinedPta(
        Builder $query,
        User $user,
        string $directionColumn,
        string $serviceColumn
    ): void {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where('actions.responsable_id', (int) $user->id);
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where($directionColumn, (int) $user->direction_id);
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where($serviceColumn, (int) $user->service_id);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function buildPasScopedQuery(User $user): Builder
    {
        $query = Pas::query();

        if ($user->isAgent()) {
            $query->whereHas('paos.ptas.actions', fn (Builder $q) => $q->where('responsable_id', (int) $user->id));
            return $query;
        }

        $this->scopePasByUser($query, $user);

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(Builder $query, string $statusColumn): array
    {
        /** @var array<string, int> $result */
        $result = (clone $query)
            ->selectRaw("{$statusColumn} as status_label, COUNT(*) as total")
            ->groupBy($statusColumn)
            ->pluck('total', 'status_label')
            ->map(fn ($value): int => (int) $value)
            ->toArray();

        return $result;
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, int> $alerts
     * @param array<string, array<string, int>> $statusBreakdown
     * @return array<string, mixed>
     */
    private function buildChartPayload(array $totals, array $alerts, array $statusBreakdown): array
    {
        $statusModules = [
            'PAO' => $statusBreakdown['paos'] ?? [],
            'PTA' => $statusBreakdown['ptas'] ?? [],
            'Actions' => $statusBreakdown['actions'] ?? [],
        ];

        $statusLabels = [];
        foreach ($statusModules as $moduleRows) {
            foreach (array_keys($moduleRows) as $statusKey) {
                if (! in_array($statusKey, $statusLabels, true)) {
                    $statusLabels[] = $statusKey;
                }
            }
        }

        $statusSeries = [];
        foreach ($statusLabels as $statusLabel) {
            $values = [];
            foreach ($statusModules as $moduleRows) {
                $values[] = (int) ($moduleRows[$statusLabel] ?? 0);
            }

            $statusSeries[] = [
                'label' => $statusLabel,
                'values' => $values,
            ];
        }

        return [
            'volumes' => [
                'labels' => ['PAS', 'PAO', 'PTA', 'Actions', 'KPI', 'Mesures KPI'],
                'values' => [
                    (int) ($totals['pas_total'] ?? 0),
                    (int) ($totals['paos_total'] ?? 0),
                    (int) ($totals['ptas_total'] ?? 0),
                    (int) ($totals['actions_total'] ?? 0),
                    (int) ($totals['kpis_total'] ?? 0),
                    (int) ($totals['kpi_mesures_total'] ?? 0),
                ],
            ],
            'alerts' => [
                'labels' => ['Actions en retard', 'KPI sous seuil'],
                'values' => [
                    (int) ($alerts['actions_en_retard'] ?? 0),
                    (int) ($alerts['mesures_kpi_sous_seuil'] ?? 0),
                ],
            ],
            'status' => [
                'module_labels' => array_keys($statusModules),
                'series' => $statusSeries,
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildDashboardData(User $user, Collection $actions): array
    {
        $actions = $actions->values();
        $validatedActions = $actions
            ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
            ->values();
        $currentYear = (int) now()->year;
        $unitMeta = $this->resolveUnitMeta($user);
        $unitRows = $this->buildUnitRows($validatedActions, (string) $unitMeta['mode']);
        $interannual = $this->buildInterannualComparison($user);
        $statusCards = $this->buildStatusCards($actions);
        $alerts = $this->buildDashboardAlertRows($actions);

        $avg = static function (Collection $items, callable $callback): float {
            if ($items->isEmpty()) {
                return 0.0;
            }

            return round((float) $items->avg($callback), 2);
        };

        $globalScores = [
            'delai' => $avg($validatedActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
            'performance' => $avg($validatedActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
            'conformite' => $avg($validatedActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)),
            'qualite' => $avg($validatedActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_qualite ?? 0)),
            'risque' => $avg($validatedActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_risque ?? 0)),
            'global' => $avg($validatedActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            'progression' => $avg($validatedActions, fn (Action $action): float => (float) ($action->progression_reelle ?? 0)),
        ];

        $monthly = collect(range(1, 12))->map(function (int $month) use ($validatedActions, $currentYear, $avg): array {
            $monthActions = $validatedActions->filter(function (Action $action) use ($currentYear, $month): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && (int) $date->year === $currentYear
                    && (int) $date->month === $month;
            })->values();

            $label = Carbon::create($currentYear, $month, 1)->locale('fr')->translatedFormat('M');

            return [
                'label' => ucfirst($label),
                'delai' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
                'performance' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
                'conformite' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)),
                'qualite' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_qualite ?? 0)),
                'risque' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_risque ?? 0)),
                'global' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            ];
        })->all();

        $actionRows = $validatedActions
            ->sortByDesc(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0))
            ->take(12)
            ->map(function (Action $action): array {
                $statusKey = $this->normalizeStatus((string) ($action->statut_dynamique ?? ''));

                return [
                    'id' => (int) $action->id,
                    'libelle' => (string) $action->libelle,
                    'direction' => (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? '-'),
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'statut' => $statusKey,
                    'statut_label' => $this->statusLabel($statusKey),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'kpi_global' => round((float) ($action->actionKpi?->kpi_global ?? 0), 2),
                    'kpi_delai' => round((float) ($action->actionKpi?->kpi_delai ?? 0), 2),
                    'kpi_performance' => round((float) ($action->actionKpi?->kpi_performance ?? 0), 2),
                    'kpi_conformite' => round((float) ($action->actionKpi?->kpi_conformite ?? 0), 2),
                    'kpi_qualite' => round((float) ($action->actionKpi?->kpi_qualite ?? 0), 2),
                    'kpi_risque' => round((float) ($action->actionKpi?->kpi_risque ?? 0), 2),
                    'date_debut' => $action->date_debut instanceof Carbon ? $action->date_debut->format('d/m/Y') : '-',
                    'date_fin' => $action->date_fin instanceof Carbon ? $action->date_fin->format('d/m/Y') : '-',
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();

        $ganttRows = $actions
            ->filter(fn (Action $action): bool => $action->date_debut instanceof Carbon && $action->date_fin instanceof Carbon)
            ->sortBy(fn (Action $action): string => $action->date_debut instanceof Carbon ? $action->date_debut->toDateString() : '')
            ->take(10)
            ->map(function (Action $action): array {
                $statusKey = $this->normalizeStatus((string) ($action->statut_dynamique ?? ''));

                return [
                    'id' => (int) $action->id,
                    'libelle' => (string) $action->libelle,
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'statut' => $statusKey,
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'date_debut' => $action->date_debut instanceof Carbon ? $action->date_debut->toDateString() : null,
                    'date_fin' => $action->date_fin instanceof Carbon ? $action->date_fin->toDateString() : null,
                    'date_debut_label' => $action->date_debut instanceof Carbon ? $action->date_debut->format('d/m') : '-',
                    'date_fin_label' => $action->date_fin instanceof Carbon ? $action->date_fin->format('d/m') : '-',
                    'color' => $this->statusColor($statusKey),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();

        $bulletRows = collect($actionRows)
            ->take(8)
            ->map(fn (array $row): array => [
                'label' => $row['libelle'],
                'real' => (float) $row['kpi_global'],
                'target' => 80.0,
                'url' => (string) $row['url'],
            ])
            ->all();

        $scatterPoints = $validatedActions
            ->filter(fn (Action $action): bool => $action->actionKpi instanceof ActionKpi)
            ->sortByDesc(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0))
            ->take(18)
            ->map(function (Action $action): array {
                $global = (float) ($action->actionKpi?->kpi_global ?? 0);

                return [
                    'label' => (string) $action->id,
                    'x' => round((float) ($action->actionKpi?->kpi_performance ?? 0), 2),
                    'y' => round((float) ($action->actionKpi?->kpi_conformite ?? 0), 2),
                    'r' => max(5, min(12, (int) round($global / 10))),
                    'color' => $global >= 80 ? '#8FC043' : ($global >= 60 ? '#3996D3' : '#F9B13C'),
                    'title' => (string) $action->libelle,
                ];
            })
            ->values()
            ->all();

        $radarDatasets = collect($unitRows)
            ->take(3)
            ->values()
            ->map(function (array $row, int $index): array {
                $palette = ['#3996D3', '#8FC043', '#F0E509'];

                return [
                    'label' => (string) $row['label'],
                    'borderColor' => $palette[$index % count($palette)],
                    'backgroundColor' => $palette[$index % count($palette)].'26',
                    'data' => [
                        round((float) ($row['kpi_delai'] ?? 0), 2),
                        round((float) ($row['kpi_performance'] ?? 0), 2),
                        round((float) ($row['kpi_conformite'] ?? 0), 2),
                        round((float) ($row['progression_moyenne'] ?? 0), 2),
                    ],
                ];
            })
            ->all();

        $topActionBars = collect($actionRows)
            ->take(6)
            ->map(fn (array $row): array => [
                'label' => (string) $row['libelle'],
                'value' => (float) $row['kpi_global'],
                'color' => $this->kpiColor((float) $row['kpi_global']),
                'url' => (string) $row['url'],
            ])
            ->all();

        return [
            'unit_mode_label' => (string) $unitMeta['label'],
            'global_scores' => $globalScores,
            'status_cards' => $statusCards,
            'monthly' => $monthly,
            'unit_rows' => $unitRows,
            'interannual' => $interannual,
            'action_rows' => $actionRows,
            'gantt_rows' => $ganttRows,
            'bullet_rows' => $bulletRows,
            'scatter_points' => $scatterPoints,
            'radar_datasets' => $radarDatasets,
            'top_action_bars' => $topActionBars,
            'alert_rows' => $alerts,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildStatusCards(Collection $actions): array
    {
        $rows = [
            'en_avance' => ['label' => 'En avance', 'color' => '#8FC043', 'bg' => '#EEF6E1', 'count' => 0],
            'en_cours' => ['label' => 'En cours', 'color' => '#3996D3', 'bg' => '#E8F3FB', 'count' => 0],
            'a_risque' => ['label' => 'A risque', 'color' => '#F0E509', 'bg' => '#FFF8D6', 'count' => 0],
            'en_retard' => ['label' => 'En retard', 'color' => '#F9B13C', 'bg' => '#FFF0DF', 'count' => 0],
            'suspendu' => ['label' => 'Suspendu', 'color' => '#7C3AED', 'bg' => '#F3E8FF', 'count' => 0],
            'annule' => ['label' => 'Annule', 'color' => '#475569', 'bg' => '#E2E8F0', 'count' => 0],
            'non_demarre' => ['label' => 'Non demarre', 'color' => '#64748B', 'bg' => '#F1F5F9', 'count' => 0],
            'acheve' => ['label' => 'Acheve', 'color' => '#1C203D', 'bg' => '#EEF1F8', 'count' => 0],
        ];

        foreach ($actions as $action) {
            $status = $this->normalizeStatus((string) ($action->statut_dynamique ?? ''));
            if (! array_key_exists($status, $rows)) {
                continue;
            }
            $rows[$status]['count']++;
        }

        return array_values($rows);
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildUnitRows(Collection $actions, string $mode): array
    {
        $groups = [];

        foreach ($actions as $action) {
            $groupKey = 'none';
            $label = 'Non renseigne';

            if ($mode === 'objectif_strategique') {
                $groupKey = (string) ($action->pta?->pao?->pasObjectif?->id ?? 0);
                $axeCode = (string) ($action->pta?->pao?->pasObjectif?->pasAxe?->code ?? '');
                $objectifCode = (string) ($action->pta?->pao?->pasObjectif?->code ?? '');
                $objectifLibelle = (string) ($action->pta?->pao?->pasObjectif?->libelle ?? 'Non renseigne');
                $label = trim($axeCode.' / '.$objectifCode.' - '.$objectifLibelle, ' /-') ?: $objectifLibelle;
            } elseif ($mode === 'direction') {
                $groupKey = (string) ($action->pta?->direction?->id ?? 0);
                $label = (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? 'Non renseigne');
            } elseif ($mode === 'service') {
                $groupKey = (string) ($action->pta?->service?->id ?? 0);
                $label = (string) ($action->pta?->service?->code ?? $action->pta?->service?->libelle ?? 'Non renseigne');
            } else {
                $groupKey = (string) $action->id;
                $label = (string) $action->libelle;
            }

            $groups[$groupKey]['label'] = $label;
            $groups[$groupKey]['items'][] = $action;
        }

        return collect($groups)
            ->map(function (array $group): array {
                /** @var Collection<int, Action> $items */
                $items = collect($group['items'] ?? []);
                $total = max(1, $items->count());
                $validated = $items->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)->count();
                $alerts = $items->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();

                return [
                    'label' => (string) ($group['label'] ?? 'Non renseigne'),
                    'actions_total' => $items->count(),
                    'progression_moyenne' => round((float) $items->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
                    'kpi_global' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                    'kpi_delai' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)), 2),
                    'kpi_performance' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)), 2),
                    'kpi_conformite' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)), 2),
                    'kpi_qualite' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_qualite ?? 0)), 2),
                    'kpi_risque' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_risque ?? 0)), 2),
                    'alertes' => $alerts,
                    'validation_pct' => round(($validated / $total) * 100, 2),
                ];
            })
            ->sortByDesc('actions_total')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDashboardAlertRows(Collection $actions): array
    {
        $today = Carbon::today();
        $rows = [];

        foreach ($actions as $action) {
            $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;
            $status = (string) ($action->statut_dynamique ?? '');
            $globalKpi = (float) ($action->actionKpi?->kpi_global ?? 0);
            $qualityKpi = (float) ($action->actionKpi?->kpi_qualite ?? 0);
            $riskKpi = (float) ($action->actionKpi?->kpi_risque ?? 0);
            $gap = max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));

            if ($deadline instanceof Carbon && $deadline->lt($today) && ! in_array($status, [ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, ActionTrackingService::STATUS_ACHEVE_HORS_DELAI], true)) {
                if (in_array($status, [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)) {
                    continue;
                }
                $daysLate = $deadline->diffInDays($today);
                $rows[] = [
                    'titre' => (float) ($action->progression_reelle ?? 0) <= 0 ? 'Action non demarree' : 'Action en retard',
                    'niveau' => $daysLate >= 7 || (float) ($action->progression_reelle ?? 0) <= 0 ? 'Critique' : 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => $daysLate.'j',
                    'kpi' => round($globalKpi, 2),
                    'kpi_qualite' => round($qualityKpi, 2),
                    'kpi_risque' => round($riskKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if ($globalKpi > 0 && $globalKpi < 60) {
                $rows[] = [
                    'titre' => 'KPI global critique',
                    'niveau' => $globalKpi < 40 ? 'Critique' : 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => number_format($globalKpi, 0).' / 100',
                    'kpi' => round($globalKpi, 2),
                    'kpi_qualite' => round($qualityKpi, 2),
                    'kpi_risque' => round($riskKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if (
                $deadline instanceof Carbon
                && $deadline->lt($today)
                && ! in_array($status, [ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, ActionTrackingService::STATUS_ACHEVE_HORS_DELAI], true)
                && ! in_array($status, [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)
                && $globalKpi > 0
                && $globalKpi < 40
            ) {
                $rows[] = [
                    'titre' => 'Retard + KPI critique',
                    'niveau' => 'Urgence',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => 'Escalade DG',
                    'kpi' => round($globalKpi, 2),
                    'kpi_qualite' => round($qualityKpi, 2),
                    'kpi_risque' => round($riskKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if ($gap >= 15) {
                $rows[] = [
                    'titre' => 'Ecart de progression',
                    'niveau' => $gap >= 30 ? 'Critique' : 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => number_format($gap, 0).' pts',
                    'kpi' => round($globalKpi, 2),
                    'kpi_qualite' => round($qualityKpi, 2),
                    'kpi_risque' => round($riskKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if (
                $action->soumise_le instanceof Carbon
                && (string) ($action->statut_validation ?? '') === ActionTrackingService::VALIDATION_SOUMISE_CHEF
                && $action->soumise_le->diffInDays($today) >= 7
            ) {
                $rows[] = [
                    'titre' => 'Validation chef bloquee',
                    'niveau' => 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => $action->soumise_le->diffInDays($today).'j',
                    'kpi' => round($globalKpi, 2),
                    'kpi_qualite' => round($qualityKpi, 2),
                    'kpi_risque' => round($riskKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-validation',
                ];
            }
        }

        return collect($rows)
            ->sortByDesc(function (array $row): int {
                return $row['niveau'] === 'Critique' ? 2 : 1;
            })
            ->take(8)
            ->values()
            ->all();
    }

    private function buildInterannualComparison(User $user): array
    {
        $paoQuery = Pao::query();
        $this->scopePao($paoQuery, $user);

        return $paoQuery
            ->with([
                'ptas' => function ($query) use ($user): void {
                    $this->scopePta($query, $user);
                    $query->with([
                        'actions' => function ($actionQuery) use ($user): void {
                            $this->scopeAction($actionQuery, $user);
                        },
                    ]);
                },
            ])
            ->orderBy('annee')
            ->get()
            ->groupBy('annee')
            ->map(function (Collection $rows, $annee): array {
                $ptas = $rows->flatMap->ptas;
                $actions = $ptas->flatMap->actions;
                $actionsTotal = $actions->count();
                $actionsValidees = $actions->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)->count();
                $validatedActions = $actions
                    ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
                    ->values();
                $actionsRetard = $actions->filter(function (Action $action): bool {
                    return $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard';
                })->count();

                return [
                    'annee' => (int) $annee,
                    'paos_total' => $rows->count(),
                    'ptas_total' => $ptas->count(),
                    'actions_total' => $actionsTotal,
                    'actions_validees' => $actionsValidees,
                    'actions_retard' => $actionsRetard,
                    'progression_moyenne' => $validatedActions->isNotEmpty()
                        ? round((float) $validatedActions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
                        : 0.0,
                    'taux_validation' => $this->completionRate($actionsValidees, $actionsTotal),
                ];
            })
            ->sortBy('annee')
            ->values()
            ->all();
    }

    private function resolveUnitMeta(User $user): array
    {
        if ($user->hasGlobalReadAccess()) {
            return ['mode' => 'objectif_strategique', 'label' => 'Objectifs strategiques'];
        }

        if ($user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
            return ['mode' => 'service', 'label' => 'Services'];
        }

        return ['mode' => 'action', 'label' => 'Actions'];
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI => 'acheve',
            ActionTrackingService::STATUS_A_RISQUE => 'a_risque',
            ActionTrackingService::STATUS_EN_AVANCE => 'en_avance',
            ActionTrackingService::STATUS_EN_RETARD => 'en_retard',
            ActionTrackingService::STATUS_SUSPENDU => 'suspendu',
            ActionTrackingService::STATUS_ANNULE => 'annule',
            ActionTrackingService::STATUS_NON_DEMARRE => 'non_demarre',
            default => 'en_cours',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'acheve' => 'Acheve',
            'a_risque' => 'A risque',
            'en_avance' => 'En avance',
            'en_retard' => 'En retard',
            'suspendu' => 'Suspendu',
            'annule' => 'Annule',
            'non_demarre' => 'Non demarre',
            default => 'En cours',
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'acheve' => '#1C203D',
            'a_risque' => '#F0E509',
            'en_avance' => '#8FC043',
            'en_retard' => '#F9B13C',
            'suspendu' => '#7C3AED',
            'annule' => '#475569',
            'non_demarre' => '#64748B',
            default => '#3996D3',
        };
    }

    private function kpiColor(float $value): string
    {
        if ($value >= 80) {
            return '#8FC043';
        }

        if ($value >= 60) {
            return '#F0E509';
        }

        if ($value > 0) {
            return '#F9B13C';
        }

        return '#94A3B8';
    }

    private function isAlertAction(Action $action): bool
    {
        $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;
        if (in_array((string) ($action->statut_dynamique ?? ''), [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)) {
            return false;
        }
        $isOverdue = $deadline instanceof Carbon
            && $deadline->lt(Carbon::today())
            && ! in_array((string) ($action->statut_dynamique ?? ''), [ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, ActionTrackingService::STATUS_ACHEVE_HORS_DELAI], true);
        $isLowKpi = (float) ($action->actionKpi?->kpi_global ?? 0) > 0 && (float) ($action->actionKpi?->kpi_global ?? 0) < 60;

        return $isOverdue
            || $isLowKpi
            || max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0)) >= 15;
    }

    private function completionRate(int $completed, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($completed / $total) * 100, 2);
    }

    private function canReadDashboard(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->isAgent();
    }
}
