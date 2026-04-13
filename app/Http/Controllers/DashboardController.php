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
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionTrackingService;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\DashboardProfileSettings;
use App\Services\WorkflowSettings;
use App\Support\SafeSql;
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
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ReportingAnalyticsService $reportingAnalyticsService,
        private readonly DashboardProfileSettings $dashboardProfileSettings,
        private readonly WorkflowSettings $workflowSettings
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
        $actionsStatistics = (clone $actions);
        $this->scopeOfficialActions($actionsStatistics);

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
        $statusColumn = SafeSql::identifier($statusColumn, [
            'statut',
            'statut_dynamique',
            'statut_validation',
            'statut_realisation',
        ]);

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
                'labels' => ['PAS', 'PAO', 'PTA', 'Actions', 'Indicateurs', 'Mesures d indicateur'],
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
                'labels' => ['Actions en retard', 'Indicateurs sous seuil'],
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
        $visibleActions = $actions->values();
        $personalActions = $visibleActions
            ->filter(fn (Action $action): bool => (int) ($action->responsable_id ?? 0) === (int) $user->id)
            ->values();
        $actions = $user->isAgent()
            ? $visibleActions
            : $visibleActions
                ->reject(fn (Action $action): bool => (int) ($action->responsable_id ?? 0) === (int) $user->id)
                ->values();
        $validatedActions = $this->validatedActions($actions);
        $dashboardRole = $this->resolveDashboardRole($user);
        $currentYear = (int) now()->year;
        $unitMeta = $this->resolveUnitMeta($user);
        $unitRows = $this->buildUnitRows($actions, (string) $unitMeta['mode']);
        $interannual = $this->buildInterannualComparison($user);
        $statusCards = $this->buildStatusCards($actions);
        $officialStatusCards = $this->buildStatusCards($validatedActions);
        $alerts = $this->buildDashboardAlertRows($actions);
        $roleDashboard = $this->buildRoleDashboard($user, $actions, $validatedActions);

        $avg = static function (Collection $items, callable $callback): float {
            if ($items->isEmpty()) {
                return 0.0;
            }

            return round((float) $items->avg($callback), 2);
        };

        $operationalGlobalScores = $this->buildGlobalScoreSummary($actions, $avg);
        $globalScores = $this->buildGlobalScoreSummary($actions, $avg);
        $operationalMonthly = $this->buildMonthlyScoreRows($actions, $currentYear, $avg, false);
        $monthly = $this->buildMonthlyScoreRows($actions, $currentYear, $avg, false);

        $actionRows = $actions
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

        $scatterPoints = $actions
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
                    'url' => route('workspace.actions.suivi', $action),
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
                    'url' => (string) ($row['url'] ?? ''),
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
            'dashboard_role' => $dashboardRole,
            'actions_index_url' => $this->actionIndexRoute(),
            'personal_actions_summary' => [
                'total' => $personalActions->count(),
                'late' => $personalActions
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
                    ->count(),
                'url' => $this->actionIndexRoute(['vue' => 'mes_actions']),
            ],
            'official_action_filters' => $this->actionCalculationSettings->officialRouteFilters(),
            'unit_mode_label' => (string) $unitMeta['label'],
            'operational_global_scores' => $operationalGlobalScores,
            'global_scores' => $globalScores,
            'operational_status_cards' => $statusCards,
            'official_status_cards' => $officialStatusCards,
            'operational_monthly' => $operationalMonthly,
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
            'role_dashboard' => $roleDashboard,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $role = $this->resolveDashboardRole($user);

        $dashboard = match ($role) {
            'agent' => $this->buildAgentRoleDashboard($actions),
            'service' => $this->buildServiceRoleDashboard($actions),
            'direction' => $this->buildDirectionRoleDashboard($user, $actions, $validatedActions),
            'planification' => $this->buildPlanificationRoleDashboard($user, $actions, $validatedActions),
            'dg' => $this->buildDgRoleDashboard($user, $actions, $validatedActions),
            'cabinet' => $this->buildCabinetRoleDashboard($user, $actions, $validatedActions),
            default => [
                'enabled' => false,
                'role' => $role,
            ],
        };

        if (($dashboard['enabled'] ?? false) !== true) {
            return $dashboard;
        }

        return $this->dashboardProfileSettings->applyToDashboard($role, $dashboard);
    }

    private function resolveDashboardRole(User $user): string
    {
        if ($user->hasRole(User::ROLE_DG)) {
            return 'dg';
        }

        if ($user->hasRole(User::ROLE_CABINET)) {
            return 'cabinet';
        }

        if ($user->hasRole(User::ROLE_ADMIN, User::ROLE_PLANIFICATION)) {
            return 'planification';
        }

        if ($user->isAgent()) {
            return 'agent';
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
            return 'service';
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return 'direction';
        }

        return 'global';
    }

    private function makeRoleCard(
        string $label,
        string|int $value,
        string $meta,
        string $href,
        string $accent,
        string $bg,
        ?string $badge = null,
        string $badgeTone = 'neutral'
    ): array {
        return [
            'code' => str($label)->slug('_')->toString(),
            'label' => $label,
            'value' => $value,
            'meta' => $meta,
            'href' => $href,
            'accent' => $accent,
            'bg' => $bg,
            'badge' => $badge,
            'badge_tone' => $badgeTone,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, int>
     */
    private function statusCounts(Collection $actions): array
    {
        $counts = [
            'non_demarre' => 0,
            'en_cours' => 0,
            'a_risque' => 0,
            'en_retard' => 0,
            'acheve' => 0,
            'suspendu' => 0,
            'annule' => 0,
            'en_avance' => 0,
        ];

        foreach ($actions as $action) {
            $status = $this->normalizeStatus((string) ($action->statut_dynamique ?? ''));
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildRoleStatusChart(Collection $actions): array
    {
        $rows = collect($this->buildStatusCards($actions))
            ->filter(fn (array $row): bool => (int) ($row['count'] ?? 0) > 0)
            ->values();

        if ($rows->isEmpty()) {
            $rows = collect($this->buildStatusCards($actions))->take(4)->values();
        }

        return [
            'labels' => $rows->pluck('label')->all(),
            'values' => $rows->map(fn (array $row): int => (int) ($row['count'] ?? 0))->all(),
            'urls' => $rows->pluck('href')->all(),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildRoleTrendChart(Collection $actions): array
    {
        $currentYear = (int) now()->year;
        $rows = collect(range(1, 12))->map(function (int $month) use ($actions, $currentYear): array {
            $monthActions = $actions->filter(function (Action $action) use ($currentYear, $month): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && (int) $date->year === $currentYear
                    && (int) $date->month === $month;
            })->values();

            $monthKey = sprintf('%04d-%02d', $currentYear, $month);

            return [
                'label' => ucfirst(Carbon::create($currentYear, $month, 1)->locale('fr')->translatedFormat('M')),
                'url' => $this->actionIndexRoute(['mois_demarrage' => $monthKey]),
                'total' => $monthActions->count(),
                'achevees' => $monthActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count(),
                'retard' => $monthActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count(),
            ];
        })->all();

        return [
            'labels' => array_column($rows, 'label'),
            'urls' => array_column($rows, 'url'),
            'datasets' => [
                ['label' => 'Actions', 'color' => '#3B82F6', 'data' => array_column($rows, 'total')],
                ['label' => 'Achevees', 'color' => '#10B981', 'data' => array_column($rows, 'achevees')],
                ['label' => 'En retard', 'color' => '#EF4444', 'data' => array_column($rows, 'retard')],
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildWeeklyLoadChart(Collection $actions): array
    {
        $start = now()->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $rows = collect(range(0, 7))->map(function (int $offset) use ($actions, $start): array {
            $weekStart = $start->copy()->addWeeks($offset)->startOfDay();
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $weekRows = $actions->filter(function (Action $action) use ($weekStart, $weekEnd): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && $date->betweenIncluded($weekStart, $weekEnd);
            })->values();

            return [
                'label' => 'S'.str_pad((string) $weekStart->isoWeek(), 2, '0', STR_PAD_LEFT),
                'url' => $this->actionIndexRoute(['week_start' => $weekStart->toDateString()]),
                'total' => $weekRows->count(),
            ];
        })->all();

        return [
            'type' => 'bar',
            'index_axis' => 'x',
            'stacked' => false,
            'labels' => array_column($rows, 'label'),
            'urls' => array_column($rows, 'url'),
            'datasets' => [
                ['label' => 'Actions', 'color' => '#1E3A8A', 'data' => array_column($rows, 'total')],
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildValidationPipelineChart(Collection $actions): array
    {
        $rows = [
            [
                'label' => 'Brouillon',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_NON_SOUMISE)->count(),
                'url' => $this->actionIndexRoute(),
            ],
            [
                'label' => 'Soumises service',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)->count(),
                'url' => $this->actionIndexRoute(),
            ],
            [
                'label' => 'Validees service',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_CHEF)->count(),
                'url' => $this->actionIndexRoute(),
            ],
            [
                'label' => 'Validees direction',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)->count(),
                'url' => $this->actionIndexRoute(),
            ],
        ];

        return [
            'type' => 'bar',
            'index_axis' => 'x',
            'stacked' => false,
            'labels' => array_column($rows, 'label'),
            'urls' => array_column($rows, 'url'),
            'datasets' => [
                ['label' => 'Actions', 'color' => '#1E3A8A', 'data' => array_column($rows, 'value')],
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildDirectionServiceChart(User $user, Collection $actions): array
    {
        $rows = collect($this->buildDirectionServiceRows($user, $actions));

        return [
            'type' => 'bar',
            'index_axis' => 'y',
            'stacked' => false,
            'labels' => $rows->pluck('service')->all(),
            'urls' => $rows->pluck('url')->all(),
            'datasets' => [
                ['label' => 'Execution', 'color' => '#10B981', 'data' => $rows->pluck('taux_execution')->all()],
                ['label' => 'Validation', 'color' => '#3B82F6', 'data' => $rows->pluck('taux_validation')->all()],
                ['label' => 'Retards', 'color' => '#EF4444', 'data' => $rows->pluck('retards')->all()],
            ],
        ];
    }

    private function progressionGap(Action $action): float
    {
        return max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));
    }

    private function requiresActionUpdate(Action $action): bool
    {
        if (in_array((string) ($action->statut_dynamique ?? ''), [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_ANNULE,
        ], true)) {
            return false;
        }

        return $this->progressionGap($action) >= 15
            || $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'non_demarre';
    }

    private function delayDays(Action $action): int
    {
        $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;

        if (! $deadline instanceof Carbon || $deadline->gte(Carbon::today())) {
            return 0;
        }

        return $deadline->diffInDays(Carbon::today());
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildScopePortfolioMetrics(User $user, Collection $actions, Collection $validatedActions): array
    {
        $statusCounts = $this->statusCounts($actions);
        $pasQuery = $this->buildPasScopedQuery($user);
        $paoQuery = Pao::query();
        $ptaQuery = Pta::query();
        $this->scopePao($paoQuery, $user);
        $this->scopePta($ptaQuery, $user);

        $directionRows = $this->buildGlobalDirectionRows($actions, 100);

        return [
            'pas_total' => (clone $pasQuery)->count(),
            'paos_total' => (clone $paoQuery)->count(),
            'ptas_total' => (clone $ptaQuery)->count(),
            'actions_total' => $actions->count(),
            'actions_valides_service' => $actions
                ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                    ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                    ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                ], true))
                ->count(),
            'actions_valides_direction' => $validatedActions->count(),
            'actions_en_retard' => $statusCounts['en_retard'],
            'alerts' => $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count(),
            'completion_rate' => $this->completionRate($statusCounts['acheve'], $actions->count()),
            'delay_rate' => $this->completionRate(max(0, $actions->count() - $statusCounts['en_retard']), $actions->count()),
            'global_score' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
            'directions_total' => collect($directionRows)->count(),
            'services_total' => $actions->pluck('pta.service_id')->filter()->unique()->count(),
            'directions_difficulte' => collect($directionRows)->filter(function (array $row): bool {
                return (float) ($row['score'] ?? 0) < 60 || (int) ($row['retards'] ?? 0) > 0;
            })->count(),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float|int>
     */
    private function buildDashboardSnapshot(Collection $actions): array
    {
        $statusCounts = $this->statusCounts($actions);
        $total = $actions->count();
        $completed = $statusCounts['acheve'];
        $late = $statusCounts['en_retard'];

        return [
            'actions_total' => $total,
            'achevees' => $completed,
            'retards' => $late,
            'completion_rate' => $this->completionRate($completed, $total),
            'delay_rate' => $this->completionRate(max(0, $total - $late), $total),
            'score' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float>
     */
    private function buildGlobalScoreSummary(Collection $actions, callable $avg): array
    {
        return [
            'delai' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
            'performance' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
            'conformite' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)),
            'qualite' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_qualite ?? 0)),
            'risque' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_risque ?? 0)),
            'global' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            'progression' => $avg($actions, fn (Action $action): float => (float) ($action->progression_reelle ?? 0)),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildMonthlyScoreRows(Collection $actions, int $currentYear, callable $avg, bool $official): array
    {
        return collect(range(1, 12))->map(function (int $month) use ($actions, $currentYear, $avg, $official): array {
            $monthActions = $actions->filter(function (Action $action) use ($currentYear, $month): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && (int) $date->year === $currentYear
                    && (int) $date->month === $month;
            })->values();

            $label = Carbon::create($currentYear, $month, 1)->locale('fr')->translatedFormat('M');
            $monthKey = sprintf('%04d-%02d', $currentYear, $month);
            $url = [
                'mois_demarrage' => $monthKey,
            ];

            if ($official) {
                $url = array_merge($url, $this->actionCalculationSettings->officialRouteFilters());
            }

            return [
                'label' => ucfirst($label),
                'month_key' => $monthKey,
                'url' => $this->actionIndexRoute($url),
                'delai' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
                'performance' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
                'conformite' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)),
                'qualite' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_qualite ?? 0)),
                'risque' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_risque ?? 0)),
                'global' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            ];
        })->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildGlobalDirectionRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
                $validatedDirection = $this->validatedActions($rows)->count();
                $score = round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);
                $directionId = (int) ($first?->pta?->direction?->id ?? 0);
                $criticalService = $rows
                    ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->libelle ?? 'Non renseigne'))
                    ->map(fn (Collection $serviceRows): int => $serviceRows->filter(fn (Action $action): bool => $this->isAlertAction($action))->count())
                    ->sortDesc()
                    ->keys()
                    ->first();

                return [
                    'direction' => (string) ($first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'validees_direction' => $validatedDirection,
                    'taux_execution' => $this->completionRate($completed, $total),
                    'taux_validation' => $this->completionRate($validatedDirection, $total),
                    'score' => $score,
                    'service_critique' => (string) ($criticalService ?? '-'),
                    'url' => $directionId > 0
                        ? $this->actionIndexRoute(['direction_id' => $directionId])
                        : route('workspace.actions.index'),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildGlobalDirectionComparisonRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $directionId = (int) ($first?->pta?->direction?->id ?? 0);
                $officialRows = $this->officialActions($rows);
                $snapshot = $this->buildDashboardSnapshot($rows);
                $officialSnapshot = $this->buildDashboardSnapshot($officialRows);
                $criticalService = $rows
                    ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->libelle ?? 'Non renseigne'))
                    ->map(fn (Collection $serviceRows): int => $serviceRows->filter(fn (Action $action): bool => $this->isAlertAction($action))->count())
                    ->sortDesc()
                    ->keys()
                    ->first();

                return [
                    'direction' => (string) ($first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'actions_total' => (int) $snapshot['actions_total'],
                    'actions_officielles' => (int) $officialSnapshot['actions_total'],
                    'achevees' => (int) $snapshot['achevees'],
                    'retards' => (int) $snapshot['retards'],
                    'validees_direction' => (int) $officialSnapshot['actions_total'],
                    'taux_execution_operationnel' => (float) $snapshot['completion_rate'],
                    'taux_execution_officiel' => (float) $officialSnapshot['completion_rate'],
                    'taux_validation' => $this->completionRate((int) $officialSnapshot['actions_total'], (int) $snapshot['actions_total']),
                    'score_operationnel' => (float) $snapshot['score'],
                    'score_officiel' => (float) $officialSnapshot['score'],
                    'service_critique' => (string) ($criticalService ?? '-'),
                    'url' => $directionId > 0
                        ? $this->actionIndexRoute(['direction_id' => $directionId])
                        : route('workspace.actions.index'),
                ];
            })
            ->sortByDesc(function (array $row): float {
                return ((float) ($row['score_officiel'] ?? 0) * 1000) + (float) ($row['score_operationnel'] ?? 0);
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildGlobalPendingValidationRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->filter(fn (Action $action): bool => in_array((string) ($action->statut_validation ?? ''), [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ], true))
            ->sortBy(fn (Action $action): string => $action->soumise_le instanceof Carbon ? $action->soumise_le->toIso8601String() : '')
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'direction' => (string) ($action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? '-'),
                    'libelle' => (string) $action->libelle,
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'soumise_le' => $action->soumise_le instanceof Carbon ? $action->soumise_le->format('d/m/Y H:i') : '-',
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildPlanificationRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $portfolio = $this->buildScopePortfolioMetrics($user, $actions, $validatedActions);
        $directionRows = $this->buildGlobalDirectionRows($actions, 8);

        return [
            'enabled' => true,
            'role' => 'planification',
            'hero' => [
                'eyebrow' => 'Vue planification',
                'title' => 'Consolidation transverse du pilotage',
                'subtitle' => 'Lecture globale des plans, des actions validees et des directions en difficulte pour superviser le dispositif.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('PAS actifs', $portfolio['pas_total'], 'Perimetre strategique', route('workspace.pas.index'), '#1E3A8A', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('PAO actifs', $portfolio['paos_total'], 'Declinaison operationnelle', route('workspace.pao.index'), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('PTA actifs', $portfolio['ptas_total'], 'Execution en cours', route('workspace.pta.index'), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Actions validees', $portfolio['actions_valides_direction'], 'Cloturees dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('Actions en retard', $portfolio['actions_en_retard'], 'Retards prioritaires', $this->actionIndexRoute(['statut' => 'en_retard']), '#EF4444', '#FEF2F2', null, 'warning'),
                $this->makeRoleCard('Indicateur global', number_format((float) $portfolio['global_score'], 0), 'Moyenne sur toutes les actions visibles', route('workspace.reporting'), '#1E3A8A', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Alertes critiques', $portfolio['alerts'], 'Points de vigilance', route('workspace.alertes', ['niveau' => 'critical']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Directions en difficulte', $portfolio['directions_difficulte'], 'Score faible ou retards', route('workspace.reporting'), '#F59E0B', '#FFFBEB', null, 'warning'),
            ],
            'status_chart' => [
                'title' => 'Repartition globale des actions',
                'subtitle' => 'Lecture transverse par statut operationnel.',
                ...$this->buildRoleStatusChart($actions),
            ],
            'trend_chart' => [
                'title' => 'Evolution globale du dispositif',
                'subtitle' => 'Actions, achevements et retards sur l annee en cours.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Performance par direction',
                'subtitle' => 'Comparaison des directions sur execution, validation et retards.',
                ...[
                    'type' => 'bar',
                    'index_axis' => 'y',
                    'stacked' => false,
                    'labels' => array_column($directionRows, 'direction'),
                    'urls' => array_column($directionRows, 'url'),
                    'datasets' => [
                        ['label' => 'Execution', 'color' => '#10B981', 'data' => array_column($directionRows, 'taux_execution')],
                        ['label' => 'Validation', 'color' => '#3B82F6', 'data' => array_column($directionRows, 'taux_validation')],
                        ['label' => 'Retards', 'color' => '#EF4444', 'data' => array_column($directionRows, 'retards')],
                    ],
                ],
            ],
            'primary_rows' => $directionRows,
            'secondary_rows' => $this->buildDirectionCriticalRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildDgRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $portfolio = $this->buildScopePortfolioMetrics($user, $actions, $validatedActions);
        $snapshot = $this->buildDashboardSnapshot($actions);
        $directionRows = $this->buildGlobalDirectionRows($actions, 8);
        $difficultyRows = collect($directionRows)
            ->filter(function (array $row): bool {
                return (float) ($row['score'] ?? 0) < 60
                    || (int) ($row['retards'] ?? 0) > 0
                    || (float) ($row['taux_validation'] ?? 0) < 50;
            })
            ->sortByDesc('retards')
            ->values()
            ->all();
        $summaryCards = [
            $this->makeRoleCard('Directions actives', $portfolio['directions_total'], 'Directions visibles', route('workspace.reporting'), '#1E3A8A', '#EFF6FF', null, 'info'),
            $this->makeRoleCard('Services actifs', $portfolio['services_total'], 'Services engages', route('workspace.reporting'), '#3B82F6', '#EFF6FF', null, 'info'),
            $this->makeRoleCard('Actions totales', $portfolio['actions_total'], 'Portefeuille institutionnel', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
            $this->makeRoleCard('Actions validees', $portfolio['actions_valides_direction'], 'Cloturees dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#10B981', '#ECFDF5', null, 'success'),
            $this->makeRoleCard('Taux validation', number_format($this->completionRate($portfolio['actions_valides_direction'], $portfolio['actions_total']), 0).'%', 'Part des actions finalement validees', $this->validatedActionIndexRoute(), '#10B981', '#ECFDF5', null, 'success'),
            $this->makeRoleCard('Execution globale', number_format((float) $snapshot['completion_rate'], 0).'%', 'Achevees / portefeuille total', $this->actionIndexRoute(['statut' => 'achevees']), '#3B82F6', '#EFF6FF', null, 'info'),
            $this->makeRoleCard('Score global', number_format((float) $snapshot['score'], 0), 'Moyenne sur toutes les actions visibles', route('workspace.reporting'), '#1E3A8A', '#EFF6FF', null, 'info'),
            $this->makeRoleCard('Alertes critiques', $portfolio['alerts'], 'Points de decision', route('workspace.alertes', ['niveau' => 'critical']), '#EF4444', '#FEF2F2', null, 'danger'),
            $this->makeRoleCard('Directions en difficulte', count($difficultyRows), 'Retards, faible score ou faible taux de validation', route('workspace.reporting'), '#F59E0B', '#FFFBEB', null, 'warning'),
        ];

        return [
            'enabled' => true,
            'role' => 'dg',
            'hero' => [
                'eyebrow' => 'Vue DG',
                'title' => 'Lecture strategique institutionnelle',
                'subtitle' => 'Lecture strategique unifiee du portefeuille visible, avec suivi explicite des actions validees dans le circuit hierarchique.',
            ],
            'summary_cards' => $summaryCards,
            'overview_enabled' => true,
            'comparison_chart_enabled' => true,
            'status_chart' => [
                'title' => 'Repartition institutionnelle des statuts',
                'subtitle' => 'Vision rapide du portefeuille total, sans filtrer sur la validation finale.',
                ...$this->buildRoleStatusChart($actions),
            ],
            'trend_chart' => [
                'title' => 'Evolution institutionnelle',
                'subtitle' => 'Lecture temporelle operationnelle du portefeuille DG.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Performance par direction',
                'subtitle' => 'Comparer par direction le volume d actions, le taux d execution, le taux de validation et le score global.',
                ...[
                    'type' => 'bar',
                    'index_axis' => 'y',
                    'stacked' => false,
                    'labels' => array_column($directionRows, 'direction'),
                    'urls' => array_column($directionRows, 'url'),
                    'datasets' => [
                        ['label' => 'Execution', 'color' => '#3B82F6', 'data' => array_column($directionRows, 'taux_execution')],
                        ['label' => 'Validation', 'color' => '#10B981', 'data' => array_column($directionRows, 'taux_validation')],
                        ['label' => 'Retards', 'color' => '#EF4444', 'data' => array_column($directionRows, 'retards')],
                        ['label' => 'Score', 'color' => '#1E3A8A', 'data' => array_column($directionRows, 'score')],
                    ],
                ],
            ],
            'primary_rows' => $directionRows,
            'secondary_rows' => $difficultyRows,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildCabinetRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $portfolio = $this->buildScopePortfolioMetrics($user, $actions, $validatedActions);
        $pendingRows = $this->buildGlobalPendingValidationRows($actions, 8);

        return [
            'enabled' => true,
            'role' => 'cabinet',
            'hero' => [
                'eyebrow' => 'Vue cabinet',
                'title' => 'Suivi transverse et appui decisionnel',
                'subtitle' => 'Lecture rapprochee des points bloquants, des validations en attente et des alertes critiques.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Actions sensibles', $portfolio['alerts'], 'Actions a forte vigilance', route('workspace.alertes', ['niveau' => 'critical']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Alertes critiques', $portfolio['alerts'], 'Niveau de risque courant', route('workspace.alertes', ['niveau' => 'critical']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Actions en retard', $portfolio['actions_en_retard'], 'Retards institutionnels', $this->actionIndexRoute(['statut' => 'en_retard']), '#F59E0B', '#FFFBEB', null, 'warning'),
                $this->makeRoleCard('Actions validees', $portfolio['actions_valides_direction'], 'Cloturees dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('Validations en attente', count($pendingRows), 'Actions a arbitrer', route('workspace.actions.index', ['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF]), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Directions en difficulte', $portfolio['directions_difficulte'], 'Suivi prioritaire', route('workspace.reporting'), '#F59E0B', '#FFFBEB', null, 'warning'),
            ],
            'status_chart' => [
                'title' => 'Repartition des statuts',
                'subtitle' => 'Vision transverse des statuts operationnels.',
                ...$this->buildRoleStatusChart($actions),
            ],
            'trend_chart' => [
                'title' => 'Evolution des alertes et retards',
                'subtitle' => 'Lecture temporelle des tensions du portefeuille.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Pipeline de validation transverse',
                'subtitle' => 'Repartition des actions entre soumission, validation service et validation direction.',
                ...$this->buildValidationPipelineChart($actions),
            ],
            'primary_rows' => $pendingRows,
            'secondary_rows' => $this->buildDirectionCriticalRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildAgentRoleDashboard(Collection $actions): array
    {
        $total = $actions->count();
        $statusCounts = $this->statusCounts($actions);
        $alertCount = $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();
        $updateCount = $actions->filter(fn (Action $action): bool => $this->requiresActionUpdate($action))->count();

        return [
            'enabled' => true,
            'role' => 'agent',
            'hero' => [
                'eyebrow' => 'Vue agent',
                'title' => 'Suivi personnel de l execution',
                'subtitle' => 'Pilotage de mes actions, de mes retards et de mes alertes sans quitter mon perimetre individuel.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Mes actions', $total, 'Portefeuille individuel', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
                $this->makeRoleCard('Mes actions en cours', $statusCounts['en_cours'], 'Execution active', $this->actionIndexRoute(['statut' => 'en_cours']), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Mes actions achevees', $statusCounts['acheve'], 'Actions terminees', $this->actionIndexRoute(['statut' => 'achevees']), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('Mes actions en retard', $statusCounts['en_retard'], 'Retards a traiter', $this->actionIndexRoute(['statut' => 'en_retard']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Mes alertes actives', $alertCount, 'Actions a surveiller', route('workspace.alertes', ['niveau' => 'warning']), '#F59E0B', '#FFFBEB', null, 'warning'),
                $this->makeRoleCard('Actions a mettre a jour', $updateCount, 'Ecarts de progression', route('workspace.actions.index', ['sort' => 'progression_desc']), '#1E3A8A', '#EFF6FF', null, 'info'),
            ],
            'status_chart' => [
                'title' => 'Repartition de mes actions',
                'subtitle' => 'Lecture immediate du portefeuille personnel par statut.',
                ...$this->buildRoleStatusChart($actions),
            ],
            'trend_chart' => [
                'title' => 'Evolution mensuelle de mes actions',
                'subtitle' => 'Volume, achevement et retards par mois de demarrage.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Charge personnelle par semaine',
                'subtitle' => 'Actions planifiees par semaine de demarrage.',
                ...$this->buildWeeklyLoadChart($actions),
            ],
            'primary_rows' => $this->buildPriorityActionRows($actions, 8),
            'secondary_rows' => $this->buildLateActionRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildServiceRoleDashboard(Collection $actions): array
    {
        $total = $actions->count();
        $statusCounts = $this->statusCounts($actions);
        $pendingServiceValidation = $actions->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)->count();
        $validatedService = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true))
            ->count();
        $alertCount = $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();
        $completionRate = $this->completionRate($statusCounts['acheve'], $total);

        return [
            'enabled' => true,
            'role' => 'service',
            'hero' => [
                'eyebrow' => 'Vue service',
                'title' => 'Pilotage du service',
                'subtitle' => 'Suivi des validations, des retards et de la charge du service avec une lecture directement exploitable.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Actions du service', $total, 'Portefeuille du service', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
                $this->makeRoleCard('Actions en cours', $statusCounts['en_cours'], 'Execution active', $this->actionIndexRoute(['statut' => 'en_cours']), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Actions achevees', $statusCounts['acheve'], 'Actions terminees', $this->actionIndexRoute(['statut' => 'achevees']), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('Actions en retard', $statusCounts['en_retard'], 'Retards du service', $this->actionIndexRoute(['statut' => 'en_retard']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Actions a valider', $pendingServiceValidation, 'Soumissions en attente', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF]), '#F59E0B', '#FFFBEB', null, 'warning'),
                $this->makeRoleCard('Actions validees service', $validatedService, 'Validation chef effectuee', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF]), '#1E3A8A', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Alertes actives', $alertCount, 'Actions critiques', route('workspace.alertes', ['niveau' => 'warning']), '#F59E0B', '#FFFBEB', null, 'warning'),
                $this->makeRoleCard('Taux execution service', number_format($completionRate, 0).'%', 'Actions achevees / total', route('workspace.actions.index', ['statut' => 'achevees']), '#10B981', '#ECFDF5', null, 'success'),
            ],
            'status_chart' => [
                'title' => 'Repartition des actions du service',
                'subtitle' => 'Lecture operationnelle du service par statut.',
                ...$this->buildRoleStatusChart($actions),
            ],
            'trend_chart' => [
                'title' => 'Evolution mensuelle du service',
                'subtitle' => 'Volume, achevement et retards sur les actions du service.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Pipeline de validation du service',
                'subtitle' => 'Ou se situent les actions entre soumission, validation service et validation direction.',
                ...$this->buildValidationPipelineChart($actions),
            ],
            'primary_rows' => $this->buildServiceValidationRows($actions, 8),
            'secondary_rows' => $this->buildServiceAgentPerformanceRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildDirectionRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $total = $actions->count();
        $statusCounts = $this->statusCounts($actions);
        $validatedDirection = $this->officialActions($actions)->count();
        $validatedService = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true))
            ->count();
        $pendingValidation = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ], true))
            ->count();
        $alertCount = $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();
        $completionRate = $this->completionRate($statusCounts['acheve'], $total);
        $delayRate = $this->completionRate(max(0, $total - $statusCounts['en_retard']), $total);
        $globalScore = round((float) $validatedActions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);

        return [
            'enabled' => true,
            'role' => 'direction',
            'hero' => [
                'eyebrow' => 'Vue direction',
                'title' => 'Pilotage directionnel et comparaison des services',
                'subtitle' => 'Lecture globale de la direction avec detail par service, validations en attente et actions critiques.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Actions direction', $total, 'Portefeuille directionnel', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
                $this->makeRoleCard('Actions en cours', $statusCounts['en_cours'], 'Execution active', $this->actionIndexRoute(['statut' => 'en_cours']), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Actions achevees', $statusCounts['acheve'], 'Actions terminees', $this->actionIndexRoute(['statut' => 'achevees']), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('Actions en retard', $statusCounts['en_retard'], 'Retards directionnels', $this->actionIndexRoute(['statut' => 'en_retard']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Actions validees service', $validatedService, 'Niveau chef atteint', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF]), '#3B82F6', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Actions validees', $validatedDirection, 'Cloturees dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('En attente validation', $pendingValidation, 'Soumises ou attente direction', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF]), '#F59E0B', '#FFFBEB', null, 'warning'),
                $this->makeRoleCard('Alertes critiques', $alertCount, 'Actions a traiter', route('workspace.alertes', ['niveau' => 'critical']), '#EF4444', '#FEF2F2', null, 'danger'),
                $this->makeRoleCard('Taux execution direction', number_format($completionRate, 0).'%', 'Actions achevees / total', route('workspace.actions.index', ['statut' => 'achevees']), '#10B981', '#ECFDF5', null, 'success'),
                $this->makeRoleCard('Respect des delais', number_format($delayRate, 0).'%', 'Actions hors retard', route('workspace.actions.index', ['statut' => 'en_retard']), '#1E3A8A', '#EFF6FF', null, 'info'),
                $this->makeRoleCard('Score global direction', number_format($globalScore, 0), 'Moyenne sur toutes les actions visibles', route('workspace.reporting'), '#1E3A8A', '#EFF6FF', null, 'info'),
            ],
            'status_chart' => [
                'title' => 'Repartition des actions de la direction',
                'subtitle' => 'Lecture macro par statut operationnel.',
                ...$this->buildRoleStatusChart($actions),
            ],
            'trend_chart' => [
                'title' => 'Evolution mensuelle de la direction',
                'subtitle' => 'Volume, achevement et retards par mois de demarrage.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Performance par service',
                'subtitle' => 'Comparaison des services sur execution, validation et retards.',
                ...$this->buildDirectionServiceChart($user, $actions),
            ],
            'primary_rows' => $this->buildDirectionServiceRows($user, $actions),
            'secondary_rows' => $this->buildDirectionCriticalRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPriorityActionRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->sortBy([
                fn (Action $action): int => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard' ? 0 : 1,
                fn (Action $action): int => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'a_risque' ? 0 : 1,
                fn (Action $action): float => -1 * $this->progressionGap($action),
                fn (Action $action): string => $action->date_echeance instanceof Carbon ? $action->date_echeance->toDateString() : '9999-12-31',
            ])
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'libelle' => (string) $action->libelle,
                    'pta' => (string) ($action->pta?->titre ?? '-'),
                    'echeance' => $action->date_echeance instanceof Carbon ? $action->date_echeance->format('d/m/Y') : '-',
                    'statut' => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildLateActionRows(Collection $actions, int $limit = 8): array
    {
        $today = Carbon::today();

        return $actions
            ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
            ->sortByDesc(function (Action $action) use ($today): int {
                $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;

                return $deadline instanceof Carbon ? $deadline->diffInDays($today) : 0;
            })
            ->take($limit)
            ->map(function (Action $action) use ($today): array {
                $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;
                $daysLate = $deadline instanceof Carbon ? $deadline->diffInDays($today) : 0;

                return [
                    'libelle' => (string) $action->libelle,
                    'echeance' => $deadline instanceof Carbon ? $deadline->format('d/m/Y') : '-',
                    'retard_jours' => $daysLate,
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildServiceValidationRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)
            ->sortBy(fn (Action $action): string => $action->soumise_le instanceof Carbon ? $action->soumise_le->toIso8601String() : '')
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'libelle' => (string) $action->libelle,
                    'agent' => (string) ($action->responsable?->name ?? '-'),
                    'soumise_le' => $action->soumise_le instanceof Carbon ? $action->soumise_le->format('d/m/Y H:i') : '-',
                    'statut' => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'retard_jours' => $this->delayDays($action),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildServiceAgentPerformanceRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->responsable?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();

                return [
                    'agent' => (string) ($first?->responsable?->name ?? 'Non assigne'),
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'taux_execution' => $this->completionRate($completed, $total),
                    'url' => route('workspace.actions.index'),
                ];
            })
            ->sortByDesc('actions_total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectionServiceRows(User $user, Collection $actions, ?int $limit = null): array
    {
        $services = $user->direction?->services()
            ->orderBy('libelle')
            ->get()
            ?? collect();

        if ($services->isEmpty()) {
            $rows = $actions
                ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->id ?? 0))
                ->map(function (Collection $rows): array {
                    $first = $rows->first();
                    $total = $rows->count();
                    $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                    $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
                    $validatedDirection = $this->officialActions($rows)->count();
                    $score = round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);
                    $serviceId = (int) ($first?->pta?->service?->id ?? 0);

                    return [
                        'service' => (string) ($first?->pta?->service?->libelle ?? 'Non renseigne'),
                        'actions_total' => $total,
                        'achevees' => $completed,
                        'retards' => $late,
                        'validees_direction' => $validatedDirection,
                        'taux_execution' => $this->completionRate($completed, $total),
                        'taux_validation' => $this->completionRate($validatedDirection, $total),
                        'score' => $score,
                        'url' => $serviceId > 0
                            ? $this->actionIndexRoute(['service_id' => $serviceId])
                            : route('workspace.actions.index'),
                    ];
                });

            return ($limit === null ? $rows : $rows->take($limit))
                ->values()
                ->all();
        }

        $actionGroups = $actions->groupBy(fn (Action $action): int => (int) ($action->pta?->service?->id ?? 0));

        $rows = $services->map(function (\App\Models\Service $service) use ($actionGroups): array {
            /** @var Collection<int, Action> $serviceActions */
            $serviceActions = $actionGroups->get((int) $service->id, collect());
            $total = $serviceActions->count();
            $completed = $serviceActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
            $late = $serviceActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
            $validatedDirection = $this->officialActions($serviceActions)->count();
            $score = $total > 0
                ? round((float) $serviceActions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2)
                : 0.0;

            return [
                'service' => (string) ($service->libelle ?: 'Service'),
                'actions_total' => $total,
                'achevees' => $completed,
                'retards' => $late,
                'validees_direction' => $validatedDirection,
                'taux_execution' => $this->completionRate($completed, $total),
                'taux_validation' => $this->completionRate($validatedDirection, $total),
                'score' => $score,
                'url' => $this->actionIndexRoute(['service_id' => (int) $service->id]),
            ];
        });

        $sortedRows = $rows
            ->sortBy([
                fn (array $row): int => $row['actions_total'] > 0 ? 0 : 1,
                fn (array $row): float => -1 * (float) ($row['score'] ?? 0),
                fn (array $row): string => (string) ($row['service'] ?? ''),
            ])
            ->values();

        return ($limit === null ? $sortedRows : $sortedRows->take($limit))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectionCriticalRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->filter(fn (Action $action): bool => $this->isAlertAction($action))
            ->sortByDesc(function (Action $action): float {
                return ($this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard' ? 100 : 0)
                    + $this->delayDays($action)
                    + max(0.0, 60 - (float) ($action->actionKpi?->kpi_global ?? 0));
            })
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'libelle' => (string) $action->libelle,
                    'direction' => (string) ($action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? '-'),
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'retard_jours' => $this->delayDays($action),
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'niveau_risque' => (float) ($action->actionKpi?->kpi_risque ?? 0),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildStatusCards(Collection $actions): array
    {
        $rows = [
            'en_avance' => ['label' => 'En avance', 'color' => '#8FC043', 'bg' => '#EEF6E1', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'en_avance'])],
            'en_cours' => ['label' => 'En cours', 'color' => '#3996D3', 'bg' => '#E8F3FB', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'en_cours'])],
            'a_risque' => ['label' => 'A risque', 'color' => '#F0E509', 'bg' => '#FFF8D6', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'a_risque'])],
            'en_retard' => ['label' => 'En retard', 'color' => '#F9B13C', 'bg' => '#FFF0DF', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'en_retard'])],
            'suspendu' => ['label' => 'Suspendu', 'color' => '#7C3AED', 'bg' => '#F3E8FF', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'suspendu'])],
            'annule' => ['label' => 'Annule', 'color' => '#475569', 'bg' => '#E2E8F0', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'annule'])],
            'non_demarre' => ['label' => 'Non demarre', 'color' => '#64748B', 'bg' => '#F1F5F9', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'non_demarre'])],
            'acheve' => ['label' => 'Acheve', 'color' => '#1C203D', 'bg' => '#EEF1F8', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'achevees'])],
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
                $url = $this->officialActionIndexRoute([
                    'pas_objectif_id' => (int) ($action->pta?->pao?->pasObjectif?->id ?? 0),
                ]);
            } elseif ($mode === 'direction') {
                $groupKey = (string) ($action->pta?->direction?->id ?? 0);
                $label = (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? 'Non renseigne');
                $url = $this->officialActionIndexRoute([
                    'direction_id' => (int) ($action->pta?->direction?->id ?? 0),
                ]);
            } elseif ($mode === 'service') {
                $groupKey = (string) ($action->pta?->service?->id ?? 0);
                $label = (string) ($action->pta?->service?->code ?? $action->pta?->service?->libelle ?? 'Non renseigne');
                $url = $this->officialActionIndexRoute([
                    'service_id' => (int) ($action->pta?->service?->id ?? 0),
                ]);
            } else {
                $groupKey = (string) $action->id;
                $label = (string) $action->libelle;
                $url = route('workspace.actions.suivi', $action);
            }

            $groups[$groupKey]['label'] = $label;
            $groups[$groupKey]['url'] = $url;
            $groups[$groupKey]['items'][] = $action;
        }

        return collect($groups)
            ->map(function (array $group): array {
                /** @var Collection<int, Action> $items */
                $items = collect($group['items'] ?? []);
                $total = max(1, $items->count());
                $validated = $this->officialActions($items)->count();
                $alerts = $items->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();

                return [
                    'label' => (string) ($group['label'] ?? 'Non renseigne'),
                    'url' => (string) ($group['url'] ?? $this->officialActionIndexRoute()),
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
                    'titre' => 'Indicateur global critique',
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
                    'titre' => 'Retard + indicateur critique',
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
                $validatedActions = $this->officialActions($actions);
                $actionsValidees = $validatedActions->count();
                $actionsRetard = $actions->filter(function (Action $action): bool {
                    return $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard';
                })->count();

                return [
                    'annee' => (int) $annee,
                    'url' => route('workspace.pao.index', ['annee' => (int) $annee]),
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

    private function scopeOfficialActions(Builder $query, string $column = 'statut_validation'): void
    {
        $this->actionCalculationSettings->applyOfficialScope($query, $column);
    }

    /**
     * @param Collection<int, Action> $actions
     * @return Collection<int, Action>
     */
    private function officialActions(Collection $actions): Collection
    {
        /** @var Collection<int, Action> $official */
        $official = $this->actionCalculationSettings->filterOfficial($actions, 'statut_validation');

        return $official;
    }

    /**
     * @param Collection<int, Action> $actions
     * @return Collection<int, Action>
     */
    private function validatedActions(Collection $actions): Collection
    {
        return $actions
            ->filter(fn (Action $action): bool => in_array($this->normalizeStatus((string) ($action->statut_dynamique ?? '')), ['acheve'], true))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedActionFilters(array $filters = []): array
    {
        return array_merge(['statut' => 'achevees'], $filters);
    }

    private function validatedActionIndexRoute(array $filters = []): string
    {
        return $this->actionIndexRoute($this->validatedActionFilters($filters));
    }

    private function officialActionIndexRoute(array $filters = []): string
    {
        return $this->actionIndexRoute(array_merge(
            $this->actionCalculationSettings->officialRouteFilters(),
            $filters
        ));
    }

    private function actionIndexRoute(array $filters = []): string
    {
        return route('workspace.actions.index', array_filter(
            $filters,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
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

