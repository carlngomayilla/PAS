<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\ActionWeek;
use App\Models\Direction;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\PaoObjectifOperationnel;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Exports\ReportingWorkbookExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitoringWebController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly AlertCenterService $alertCenter,
        private readonly AlertReadService $alertReadService,
        private readonly ReportingWorkbookExporter $reportingWorkbookExporter,
        private readonly ReportingAnalyticsService $reportingAnalyticsService
    ) {
    }

    public function pilotage(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);
        $roleProfile = $this->buildMonitoringRoleProfile($user, 'pilotage');

        $today = Carbon::today()->toDateString();

        $pas = $this->buildPasScopedQuery($user);
        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $kpis = Kpi::query();
        $mesures = KpiMesure::query();
        $objectifsOperationnels = PaoObjectifOperationnel::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeMesure($mesures, $user);
        $this->scopeObjectifOperationnel($objectifsOperationnels, $user);
        $actionsStatistics = (clone $actions);
        $this->scopeActionStatistics($actionsStatistics);

        $totals = [
            'pas_total' => (clone $pas)->count(),
            'paos_total' => (clone $paos)->count(),
            'ptas_total' => (clone $ptas)->count(),
            'actions_total' => (clone $actions)->count(),
            'actions_validees' => (clone $actionsStatistics)->count(),
            'kpis_total' => (clone $kpis)->count(),
            'kpi_mesures_total' => (clone $mesures)->count(),
            'objectifs_operationnels_total' => (clone $objectifsOperationnels)->count(),
        ];

        $paosValides = (clone $paos)->whereIn('statut', ['valide', 'verrouille'])->count();
        $ptasValides = (clone $ptas)->whereIn('statut', ['valide', 'verrouille'])->count();
        $actionsTerminees = (clone $actions)
            ->whereIn('statut_dynamique', ['acheve_dans_delai', 'acheve_hors_delai'])
            ->count();
        $objectifsOperationnelsTermines = (clone $objectifsOperationnels)->where('statut_realisation', 'termine')->count();
        $kpisAvecMesures = (clone $kpis)->has('mesures')->count();

        $actionsRetard = (clone $actions)
            ->where(function (Builder $q) use ($today): void {
                $q->where('statut_dynamique', 'en_retard')
                    ->orWhere(function (Builder $subQuery) use ($today): void {
                        $subQuery->whereNotNull('date_echeance')
                            ->whereDate('date_echeance', '<', $today)
                            ->whereNotIn('statut_dynamique', $this->completedActionStatuses());
                    });
            })
            ->count();

        $kpiSousSeuilQuery = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');
        $this->scopeJoinedPta($kpiSousSeuilQuery, $user, 'ptas.direction_id', 'ptas.service_id');

        $financementRequis = (clone $actions)->where('financement_requis', true)->count();
        $financementDocumente = (clone $actions)
            ->where('financement_requis', true)
            ->whereNotNull('description_financement')
            ->where('description_financement', '!=', '')
            ->whereNotNull('source_financement')
            ->where('source_financement', '!=', '')
            ->count();

        $pasSansPao = $this->countPasSansPao($user);
        $paoSansPta = (clone $paos)->doesntHave('ptas')->count();
        $ptaSansAction = (clone $ptas)->doesntHave('actions')->count();
        $actionSansKpi = (clone $actions)->doesntHave('kpis')->count();
        $kpiSansMesure = (clone $kpis)->doesntHave('mesures')->count();

        $actionsProches = (clone $actions)
            ->with(['pta:id,titre,direction_id,service_id', 'responsable:id,name,email'])
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '>=', $today)
            ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
            ->orderBy('date_echeance')
            ->limit(10)
            ->get();
        $dgComparison = $roleProfile['role'] === 'dg'
            ? $this->buildDgMonitoringComparison($user)
            : null;

        return view('workspace.monitoring.pilotage', [
            'generatedAt' => now(),
            'roleProfile' => $roleProfile,
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
            ],
            'totals' => $totals,
            'completion' => [
                'paos_valides_pct' => $this->completionRate($paosValides, $totals['paos_total']),
                'ptas_valides_pct' => $this->completionRate($ptasValides, $totals['ptas_total']),
                'actions_terminees_pct' => $this->completionRate($actionsTerminees, $totals['actions_total']),
                'actions_validees_pct' => $this->completionRate($totals['actions_validees'], $totals['actions_total']),
                'obj_ops_termines_pct' => $this->completionRate($objectifsOperationnelsTermines, $totals['objectifs_operationnels_total']),
                'kpis_couverts_pct' => $this->completionRate($kpisAvecMesures, $totals['kpis_total']),
                'financement_documente_pct' => $this->completionRate($financementDocumente, $financementRequis),
            ],
            'statusBreakdown' => [
                'pas' => $this->countByStatus($pas, 'statut'),
                'paos' => $this->countByStatus($paos, 'statut'),
                'ptas' => $this->countByStatus($ptas, 'statut'),
                'actions' => $this->countByStatus($actions, 'statut_dynamique'),
                'actions_validation' => $this->countByStatus($actions, 'statut_validation'),
                'objectifs_operationnels' => $this->countByStatus($objectifsOperationnels, 'statut_realisation'),
            ],
            'pipelineGaps' => [
                'pas_sans_pao' => $pasSansPao,
                'pao_sans_pta' => $paoSansPta,
                'pta_sans_action' => $ptaSansAction,
                'action_sans_kpi' => $actionSansKpi,
                'kpi_sans_mesure' => $kpiSansMesure,
            ],
            'alertes' => [
                'actions_en_retard' => $actionsRetard,
                'mesures_kpi_sous_seuil' => $kpiSousSeuilQuery->count(),
            ],
            'pasConsolidation' => $this->buildPasConsolidation($user),
            'interannualComparison' => $this->buildInterannualComparison($user),
            'actionsProches' => $actionsProches,
            'dgComparison' => $dgComparison,
        ]);
    }

    public function reporting(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user);
        $payload['roleProfile'] = $this->buildMonitoringRoleProfile($user, 'reporting');
        $payload['dgComparison'] = ($payload['roleProfile']['role'] ?? null) === 'dg'
            ? $this->buildDgMonitoringComparison($user)
            : null;

        return view('workspace.monitoring.reporting', $payload);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user, true, true);
        $generatedAt = $payload['generatedAt'];
        $filename = 'reporting_anbg_'.$generatedAt->format('Ymd_His').'.xlsx';
        $tempPath = $this->reportingWorkbookExporter->create($payload);

        return response()->streamDownload(function () use ($tempPath): void {
            $stream = fopen($tempPath, 'rb');
            if (! is_resource($stream)) {
                @unlink($tempPath);

                return;
            }

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
                @unlink($tempPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user, true, true);
        $generatedAt = $payload['generatedAt'];
        $filename = 'reporting_anbg_'.$generatedAt->format('Ymd_His').'.pdf';

        return Pdf::loadView('workspace.monitoring.reporting-pdf', $payload)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    public function alertes(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $limit = max(1, min(100, (int) $request->integer('limit', 20)));
        $activeLevel = in_array((string) $request->string('niveau'), ['all', 'urgence', 'critical', 'warning', 'info'], true)
            ? (string) $request->string('niveau')
            : 'all';
        $activeState = in_array((string) $request->string('etat'), ['all', 'unread', 'read'], true)
            ? (string) $request->string('etat')
            : 'all';
        $fetchLimit = ($activeLevel !== 'all' || $activeState !== 'all')
            ? max($limit, 100)
            : $limit;
        $readFingerprints = $this->alertReadService->readFingerprintsForUser($user);
        $items = $this->alertCenter
            ->buildForUser($user, $fetchLimit)
            ->map(function (array $item) use ($readFingerprints, $limit, $activeLevel, $activeState): array {
                $item['is_unread'] = ! in_array((string) $item['fingerprint'], $readFingerprints, true);
                $item['read_url'] = route('workspace.alertes.read', [
                    'type' => $item['source_type'],
                    'id' => $item['source_id'],
                    'limit' => $limit,
                    'niveau' => $activeLevel !== 'all' ? $activeLevel : null,
                    'etat' => $activeState !== 'all' ? $activeState : null,
                ]);

                return $item;
            })
            ->values();

        $reportingPayload = $this->reportingAnalyticsService->buildPayload($user, false, false);

        return view('workspace.monitoring.alertes', [
            'limit' => $limit,
            'alertItems' => $items,
            'kpiSummary' => $reportingPayload['kpiSummary'] ?? [],
            'summary' => [
                'total' => $items->count(),
                'unread' => $items->where('is_unread', true)->count(),
                'urgence' => $items->where('niveau', 'urgence')->count(),
                'critical' => $items->where('niveau', 'critical')->count(),
                'warning' => $items->where('niveau', 'warning')->count(),
                'info' => $items->where('niveau', 'info')->count(),
            ],
            'levelUnreadCounts' => [
                'urgence' => $items->where('niveau', 'urgence')->where('is_unread', true)->count(),
                'critical' => $items->where('niveau', 'critical')->where('is_unread', true)->count(),
                'warning' => $items->where('niveau', 'warning')->where('is_unread', true)->count(),
                'info' => $items->where('niveau', 'info')->where('is_unread', true)->count(),
            ],
            'activeLevel' => $activeLevel,
            'activeState' => $activeState,
        ]);
    }

    public function alertesDropdown(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $limit = max(1, min(12, (int) $request->integer('limit', 6)));
        $readFingerprints = $this->alertReadService->readFingerprintsForUser($user);
        $reportingPayload = $this->reportingAnalyticsService->buildPayload($user, false, false);
        $summary = $this->alertCenter->summaryForUser($user, $readFingerprints);

        $items = $this->alertCenter
            ->buildForUser($user, $limit)
            ->map(function (array $item) use ($readFingerprints, $limit): array {
                $item['is_unread'] = ! in_array((string) $item['fingerprint'], $readFingerprints, true);
                $item['read_url'] = route('workspace.alertes.read', [
                    'type' => $item['source_type'],
                    'id' => $item['source_id'],
                    'limit' => $limit,
                ]);

                return $item;
            })
            ->values();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'unread' => (int) ($summary['unread'] ?? 0),
                'urgence' => (int) ($summary['urgence'] ?? 0),
                'critical' => (int) ($summary['critical'] ?? 0),
                'warning' => (int) ($summary['warning'] ?? 0),
                'info' => (int) ($summary['info'] ?? 0),
            ],
            'kpi_summary' => $reportingPayload['kpiSummary'] ?? [],
            'items' => $items,
            'center_url' => route('workspace.alertes'),
        ]);
    }

    public function readAlerte(Request $request, string $type, int $id): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $alert = $this->alertCenter->findForUser($user, $type, $id);
        if ($alert === null) {
            abort(404);
        }

        $this->alertReadService->markAlertAsRead($user, $alert);
        $this->markAlertNotificationsAsRead($user);

        return redirect()->to((string) ($alert['target_url'] ?? route('workspace.alertes')));
    }

    public function readAllAlertes(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $limit = max(1, min(100, (int) $request->integer('limit', 20)));
        $fingerprints = $this->alertCenter
            ->buildForUser($user, $limit)
            ->pluck('fingerprint')
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        $this->alertReadService->markFingerprintsAsRead($user, $fingerprints);
        $this->markAlertNotificationsAsRead($user);

        return back()->with('success', 'Les alertes visibles ont ete marquees comme lues.');
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

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    private function scopeActionStatistics(Builder $query, string $column = 'statut_validation'): void
    {
        $query->where($column, ActionTrackingService::VALIDATION_VALIDEE_DIRECTION);
    }

    private function resolveActionAlertUrl(Action $action): string
    {
        return route('workspace.actions.suivi', $action).'#action-status';
    }

    private function resolveKpiAlertUrl(KpiMesure $mesure): ?string
    {
        $action = $mesure->kpi?->action;
        if (! $action instanceof Action) {
            return null;
        }

        return route('workspace.actions.suivi', $action).'#action-status';
    }

    private function resolveActionLogAlertUrl(ActionLog $log): ?string
    {
        $action = $log->action;
        if (! $action instanceof Action) {
            return null;
        }

        if ($log->week !== null) {
            return route('workspace.actions.suivi', $action).'#action-week-'.$log->week->id;
        }

        return route('workspace.actions.suivi', $action).'#action-logs';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInterannualComparison(User $user): array
    {
        $paoQuery = Pao::query();
        $this->scopePao($paoQuery, $user);

        $paos = $paoQuery
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
            ->get();

        return $paos
            ->groupBy('annee')
            ->map(function ($rows, $annee): array {
                $ptas = $rows->flatMap->ptas;
                $actions = $ptas->flatMap->actions;
                $actionsTotal = $actions->count();
                $actionsValidees = $actions
                    ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
                    ->count();
                $actionsRetard = $actions
                    ->where('statut_dynamique', ActionTrackingService::STATUS_EN_RETARD)
                    ->count();
                $progressionMoyenne = $actionsTotal > 0
                    ? round((float) $actions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
                    : 0.0;

                return [
                    'annee' => (int) $annee,
                    'paos_total' => $rows->count(),
                    'ptas_total' => $ptas->count(),
                    'actions_total' => $actionsTotal,
                    'actions_validees' => $actionsValidees,
                    'actions_retard' => $actionsRetard,
                    'progression_moyenne' => $progressionMoyenne,
                    'taux_validation' => $this->completionRate($actionsValidees, $actionsTotal),
                ];
            })
            ->sortBy('annee')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPasConsolidation(User $user): array
    {
        $pasRows = $this->buildPasScopedQuery($user)
            ->with(['axes.objectifs'])
            ->orderByDesc('periode_fin')
            ->get();

        if ($pasRows->isEmpty()) {
            return [];
        }

        $paoQuery = Pao::query()
            ->whereIn('pas_id', $pasRows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->scopePao($paoQuery, $user);

        $directionScopeIds = $this->scopedDirectionIds($user);

        $paos = $paoQuery
            ->with([
                'direction:id,code,libelle',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'ptas' => function ($query) use ($user): void {
                    $this->scopePta($query, $user);
                    $query->with([
                        'service:id,code,libelle',
                        'actions' => function ($actionQuery) use ($user): void {
                            $this->scopeAction($actionQuery, $user);
                        },
                    ]);
                },
            ])
            ->get();

        $paosByPas = $paos->groupBy('pas_id');
        $paosByObjectif = $paos->groupBy('pas_objectif_id');

        return $pasRows->map(function (Pas $pas) use ($paosByObjectif, $paosByPas, $directionScopeIds): array {
            $paos = $paosByPas->get((int) $pas->id, collect());
            $ptas = $paos->flatMap->ptas;
            $actions = $ptas->flatMap->actions;
            $actionsTotal = $actions->count();
            $actionsValidees = $actions
                ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
                ->count();

            $axes = $pas->axes->map(function ($axe) use ($directionScopeIds, $paosByObjectif): array {
                $objectifs = $axe->objectifs->map(function ($objectif) use ($directionScopeIds, $paosByObjectif): array {
                    $objectifPaos = $paosByObjectif->get((int) $objectif->id, collect());
                    $objectifActions = $objectifPaos->flatMap->ptas->flatMap->actions;
                    $objectifActionsTotal = $objectifActions->count();
                    $objectifActionsValidees = $objectifActions
                        ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
                        ->count();
                    $coveredDirectionIds = $objectifPaos
                        ->pluck('direction_id')
                        ->filter()
                        ->map(static fn ($value): int => (int) $value)
                        ->unique()
                        ->values()
                        ->all();
                    $missingDirectionIds = array_values(array_diff($directionScopeIds, $coveredDirectionIds));
                    $missingDirections = Direction::query()
                        ->whereIn('id', $missingDirectionIds)
                        ->orderBy('code')
                        ->get(['id', 'code', 'libelle'])
                        ->map(fn (Direction $direction): string => (string) ($direction->code.' - '.$direction->libelle))
                        ->all();

                    return [
                        'code' => (string) ($objectif->code ?: ''),
                        'libelle' => (string) ($objectif->libelle ?: ''),
                        'paos_total' => $objectifPaos->count(),
                        'directions_couvertes' => count($coveredDirectionIds),
                        'directions_attendues' => count($directionScopeIds),
                        'directions_manquantes' => $missingDirections,
                        'actions_total' => $objectifActionsTotal,
                        'actions_validees' => $objectifActionsValidees,
                        'taux_realisation' => $this->completionRate($objectifActionsValidees, $objectifActionsTotal),
                    ];
                })->values()->all();

                $axePaos = collect($objectifs)->sum(fn (array $objectif): int => (int) $objectif['paos_total']);
                $axeActionsTotal = collect($objectifs)->sum(fn (array $objectif): int => (int) $objectif['actions_total']);
                $axeActionsValidees = collect($objectifs)->sum(fn (array $objectif): int => (int) $objectif['actions_validees']);

                return [
                    'code' => (string) ($axe->code ?: ''),
                    'libelle' => (string) ($axe->libelle ?: ''),
                    'objectifs_total' => $axe->objectifs->count(),
                    'paos_total' => $axePaos,
                    'actions_total' => $axeActionsTotal,
                    'actions_validees' => $axeActionsValidees,
                    'taux_realisation' => $this->completionRate($axeActionsValidees, $axeActionsTotal),
                    'objectifs' => $objectifs,
                ];
            })->values()->all();

            return [
                'id' => (int) $pas->id,
                'titre' => (string) $pas->titre,
                'periode' => (string) $pas->periode_debut.'-'.$pas->periode_fin,
                'statut' => (string) $pas->statut,
                'axes_total' => $pas->axes->count(),
                'objectifs_total' => $pas->axes->sum(fn ($axe): int => $axe->objectifs->count()),
                'paos_total' => $paos->count(),
                'ptas_total' => $ptas->count(),
                'actions_total' => $actionsTotal,
                'actions_validees' => $actionsValidees,
                'progression_moyenne' => $actionsTotal > 0
                    ? round((float) $actions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
                    : 0.0,
                'taux_realisation' => $this->completionRate($actionsValidees, $actionsTotal),
                'axes' => $axes,
            ];
        })->values()->all();
    }

    /**
     * @return array<int, int>
     */
    private function scopedDirectionIds(User $user): array
    {
        if ($user->hasGlobalReadAccess()) {
            return Direction::query()
                ->where('actif', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->direction_id !== null) {
            return [(int) $user->direction_id];
        }

        return [];
    }

    private function scopeKpi(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
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

    private function scopeMesure(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
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

    private function scopeObjectifOperationnel(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('objectifStrategique.paoAxe.pao', function (Builder $subQuery) use ($user): void {
                $subQuery->where('direction_id', (int) $user->direction_id);
            });
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('objectifStrategique.paoAxe.pao.ptas', function (Builder $subQuery) use ($user): void {
                $subQuery->where('service_id', (int) $user->service_id);
            });
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

        $this->scopePasByUser($query, $user);

        return $query;
    }

    private function countPasSansPao(User $user): int
    {
        $pasRows = $this->buildPasScopedQuery($user)
            ->with([
                'directions:id',
                'axes.objectifs.paos:id,pas_objectif_id,direction_id',
                'axes.objectifs.paos.ptas:id,pao_id,service_id',
            ])
            ->get();

        $scopedDirectionIds = $this->scopedDirectionIds($user);

        return $pasRows->filter(function (Pas $pas) use ($user, $scopedDirectionIds): bool {
            $pasDirectionIds = $pas->directions
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $expectedDirectionIds = $user->hasGlobalReadAccess()
                ? array_values(array_intersect($scopedDirectionIds, $pasDirectionIds !== [] ? $pasDirectionIds : $scopedDirectionIds))
                : (($user->direction_id !== null && in_array((int) $user->direction_id, $pasDirectionIds, true))
                    ? [(int) $user->direction_id]
                    : []);

            if ($expectedDirectionIds === []) {
                return false;
            }

            foreach ($pas->axes as $axe) {
                foreach ($axe->objectifs as $objectif) {
                    if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                        $coveredForService = $objectif->paos->contains(function ($pao) use ($user): bool {
                            return (int) $pao->direction_id === (int) $user->direction_id
                                && $pao->ptas->contains(fn ($pta): bool => (int) $pta->service_id === (int) $user->service_id);
                        });

                        if (! $coveredForService) {
                            return true;
                        }

                        continue;
                    }

                    $coveredDirectionIds = $objectif->paos
                        ->pluck('direction_id')
                        ->filter()
                        ->map(static fn ($id): int => (int) $id)
                        ->unique()
                        ->values()
                        ->all();

                    if (array_diff($expectedDirectionIds, $coveredDirectionIds) !== []) {
                        return true;
                    }
                }
            }

            return false;
        })->count();
    }

    private function completionRate(int $done, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($done / $total) * 100, 2);
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
     * @return array{
     *     operational: array<string, float|int>,
     *     official: array<string, float|int>,
     *     direction_rows: array<int, array<string, mixed>>
     * }
     */
    private function buildDgMonitoringComparison(User $user): array
    {
        $actions = Action::query()
            ->with([
                'actionKpi:id,action_id,kpi_global',
                'pta:id,direction_id,service_id,titre',
                'pta.direction:id,libelle',
                'pta.service:id,libelle',
            ]);
        $this->scopeAction($actions, $user);

        $rows = $actions->get();
        $officialRows = $rows
            ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
            ->values();

        return [
            'operational' => $this->buildMonitoringSnapshot($rows),
            'official' => $this->buildMonitoringSnapshot($officialRows),
            'direction_rows' => $this->buildMonitoringDirectionComparisonRows($rows, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float|int>
     */
    private function buildMonitoringSnapshot(Collection $actions): array
    {
        $total = $actions->count();
        $completed = $actions->filter(function (Action $action): bool {
            return in_array((string) $action->statut_dynamique, [
                ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ], true);
        })->count();
        $late = $actions->filter(fn (Action $action): bool => (string) $action->statut_dynamique === ActionTrackingService::STATUS_EN_RETARD)->count();

        return [
            'actions_total' => $total,
            'actions_achevees' => $completed,
            'actions_retard' => $late,
            'completion_rate' => $this->completionRate($completed, $total),
            'delay_rate' => $this->completionRate(max(0, $total - $late), $total),
            'score' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildMonitoringDirectionComparisonRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $directionId = (int) ($first?->pta?->direction?->id ?? 0);
                $officialRows = $rows
                    ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
                    ->values();
                $operational = $this->buildMonitoringSnapshot($rows);
                $official = $this->buildMonitoringSnapshot($officialRows);

                return [
                    'direction' => (string) ($first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'actions_total' => (int) $operational['actions_total'],
                    'actions_officielles' => (int) $official['actions_total'],
                    'taux_execution_operationnel' => (float) $operational['completion_rate'],
                    'taux_execution_officiel' => (float) $official['completion_rate'],
                    'score_operationnel' => (float) $operational['score'],
                    'score_officiel' => (float) $official['score'],
                    'retards' => (int) $operational['actions_retard'],
                    'url' => $directionId > 0
                        ? route('workspace.actions.index', ['direction_id' => $directionId])
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
     * @return array<string, string>
     */
    private function buildMonitoringRoleProfile(User $user, string $page): array
    {
        $role = $this->resolveMonitoringRole($user);
        $profiles = [
            'pilotage' => [
                'default' => [
                    'eyebrow' => 'Pilotage global',
                    'title' => 'Pilotage global et consolidation transverse',
                    'subtitle' => 'Vue consolidee des volumes, ruptures de chaine, retards et realisation par annee.',
                ],
                'service' => [
                    'eyebrow' => 'Pilotage service',
                    'title' => 'Suivi du service et ruptures de chaine',
                    'subtitle' => 'Lecture metier du service sur les plans, les ruptures, les retards et la couverture d execution.',
                ],
                'direction' => [
                    'eyebrow' => 'Pilotage direction',
                    'title' => 'Pilotage directionnel et consolidation des services',
                    'subtitle' => 'Lecture consolidee des volumes, validations et ruptures de la direction et de ses services.',
                ],
                'dg' => [
                    'eyebrow' => 'Pilotage DG',
                    'title' => 'Pilotage institutionnel',
                    'subtitle' => 'Vue strategique haute des plans, des ruptures et des tensions majeures du portefeuille.',
                ],
                'cabinet' => [
                    'eyebrow' => 'Pilotage cabinet',
                    'title' => 'Suivi transversal pour arbitrage',
                    'subtitle' => 'Lecture rapprochee des retards, ruptures et actions sensibles utiles a l appui decisionnel.',
                ],
            ],
            'reporting' => [
                'default' => [
                    'eyebrow' => 'Reporting consolide',
                    'title' => 'Centre d export et de diffusion',
                    'subtitle' => 'Les graphes et les tableaux de reporting ont ete centralises dans le dashboard analytique et servent ici a l export et a la diffusion.',
                ],
                'service' => [
                    'eyebrow' => 'Reporting service',
                    'title' => 'Diffusion consolidee du service',
                    'subtitle' => 'Exports et vues consolidees du service avec lecture rapide des niveaux provisoire, valide et officiel.',
                ],
                'direction' => [
                    'eyebrow' => 'Reporting direction',
                    'title' => 'Centre de diffusion directionnel',
                    'subtitle' => 'Exports et lectures consolidees de la direction avec un socle officiel base sur la validation finale.',
                ],
                'dg' => [
                    'eyebrow' => 'Reporting DG',
                    'title' => 'Centre d export institutionnel',
                    'subtitle' => 'Point unique de diffusion des vues consolidees et officielles pour l arbitrage strategique.',
                ],
                'cabinet' => [
                    'eyebrow' => 'Reporting cabinet',
                    'title' => 'Centre de diffusion transverse',
                    'subtitle' => 'Exports consolides et vue rapide des familles analytiques utiles a l accompagnement DG.',
                ],
            ],
        ];

        $pageProfiles = $profiles[$page] ?? [];
        $profile = $pageProfiles[$role] ?? ($pageProfiles['default'] ?? [
            'eyebrow' => $user->roleLabel(),
            'title' => 'Lecture consolidee',
            'subtitle' => 'Vue filtree selon le perimetre courant.',
        ]);

        return $profile + [
            'role' => $role,
            'role_label' => $user->roleLabel(),
        ];
    }

    private function resolveMonitoringRole(User $user): string
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

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return 'direction';
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
            return 'service';
        }

        return 'reader';
    }

    /**
     * @return array{
     *     funnel: array{labels: list<string>, values: list<int>},
     *     status_by_unit: array{
     *         unit_label: string,
     *         labels: list<string>,
     *         datasets: list<array{label: string, data: list<int>}>
     *     },
     *     progress_weekly: array{labels: list<string>, reel: list<float>, theorique: list<float>},
     *     kpi_trend: array{labels: list<string>, valeurs: list<float>, cibles: list<float>, seuils: list<float>},
     *     retard_heatmap: array{weeks: list<string>, units: list<string>, matrix: list<list<int>>, max: int},
     *     critical_gantt: array{
     *         min: string,
     *         max: string,
     *         items: list<array{
     *             label: string,
     *             start: string,
     *             end: string,
     *             progress: float,
     *             status: string,
     *             score: float
     *         }>
     *     },
     *     resource_treemap: array{labels: list<string>, values: list<float>, total: float},
     *     risk_pareto: array{labels: list<string>, counts: list<int>, cumulative_pct: list<float>},
     *     top_risks: array{
     *         labels: list<string>,
     *         scores: list<float>,
     *         rows: list<array{
     *             action: string,
     *             score: float,
     *             statut: string,
     *             echeance: string,
     *             responsable: string
     *         }>
     *     },
     *     performance_gauge: array{labels: list<string>, values: list<float>}
     * }
     */
    private function buildChartsPayload(User $user): array
    {
        $today = Carbon::today();
        $todayDate = $today->toDateString();
        $completedStatuses = $this->completedActionStatuses();

        $pasCount = $this->buildPasScopedQuery($user)->count();

        $paoQuery = Pao::query();
        $ptaQuery = Pta::query();
        $actionQuery = Action::query();
        $this->scopePao($paoQuery, $user);
        $this->scopePta($ptaQuery, $user);
        $this->scopeAction($actionQuery, $user);

        $funnel = [
            'labels' => ['PAS', 'PAO', 'PTA', 'Actions'],
            'values' => [
                (int) $pasCount,
                (int) (clone $paoQuery)->count(),
                (int) (clone $ptaQuery)->count(),
                (int) (clone $actionQuery)->count(),
            ],
        ];

        $unitLabel = 'Direction';
        $unitColumn = 'ptas.direction_id';
        $unitNames = Direction::query()
            ->pluck('libelle', 'id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->toArray();

        if (! $user->hasGlobalReadAccess() && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
            $unitLabel = 'Service';
            $unitColumn = 'ptas.service_id';
            $serviceQuery = Service::query();
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $serviceQuery->where('direction_id', (int) $user->direction_id);
            }
            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $serviceQuery->where('id', (int) $user->service_id);
            }
            $unitNames = $serviceQuery
                ->pluck('libelle', 'id')
                ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
                ->toArray();
        }

        $statusRows = Action::query()
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->selectRaw("{$unitColumn} as unit_id, actions.statut_dynamique as status_label, COUNT(*) as total")
            ->groupBy($unitColumn, 'actions.statut_dynamique');
        $this->scopeJoinedPta($statusRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $statusMatrix = [];
        $statusTotals = [];
        $unitTotals = [];
        foreach ($statusRows->get() as $row) {
            $unitId = (int) ($row->unit_id ?? 0);
            if ($unitId <= 0) {
                continue;
            }
            $status = trim((string) ($row->status_label ?? 'inconnu'));
            if ($status === '') {
                $status = 'inconnu';
            }
            $total = (int) ($row->total ?? 0);
            if ($total <= 0) {
                continue;
            }
            $statusMatrix[$status][$unitId] = $total;
            $statusTotals[$status] = ($statusTotals[$status] ?? 0) + $total;
            $unitTotals[$unitId] = ($unitTotals[$unitId] ?? 0) + $total;
        }
        arsort($unitTotals);
        arsort($statusTotals);

        $unitIds = array_keys($unitTotals);
        if ($unitIds === [] && $user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $unitIds = [(int) $user->service_id];
        }
        if ($unitIds === [] && $user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null && $unitColumn === 'ptas.direction_id') {
            $unitIds = [(int) $user->direction_id];
        }

        $statusLabels = array_map(
            fn (int $id): string => $unitNames[$id] ?? ('#'.$id),
            $unitIds
        );
        $statusNames = array_slice(array_keys($statusTotals), 0, 6);
        $statusDatasets = [];
        foreach ($statusNames as $statusName) {
            $statusDatasets[] = [
                'label' => $statusName,
                'data' => array_map(
                    fn (int $unitId): int => (int) ($statusMatrix[$statusName][$unitId] ?? 0),
                    $unitIds
                ),
            ];
        }

        $progressRows = ActionWeek::query()
            ->select([
                'action_weeks.date_debut',
                'action_weeks.progression_reelle',
                'action_weeks.progression_theorique',
            ])
            ->join('actions', 'actions.id', '=', 'action_weeks.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('action_weeks.date_debut')
            ->orderBy('action_weeks.date_debut');
        $this->scopeJoinedPta($progressRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $progressBuckets = [];
        foreach ($progressRows->get() as $row) {
            $weekStart = Carbon::parse((string) $row->date_debut)->startOfWeek(Carbon::MONDAY)->toDateString();
            if (! isset($progressBuckets[$weekStart])) {
                $progressBuckets[$weekStart] = [
                    'sum_reel' => 0.0,
                    'sum_theorique' => 0.0,
                    'count' => 0,
                ];
            }
            $progressBuckets[$weekStart]['sum_reel'] += (float) ($row->progression_reelle ?? 0);
            $progressBuckets[$weekStart]['sum_theorique'] += (float) ($row->progression_theorique ?? 0);
            $progressBuckets[$weekStart]['count']++;
        }
        ksort($progressBuckets);
        $progressBuckets = array_slice($progressBuckets, -12, 12, true);
        $progressLabels = [];
        $progressReel = [];
        $progressTheorique = [];
        foreach ($progressBuckets as $weekStart => $bucket) {
            $date = Carbon::parse($weekStart);
            $count = max(1, (int) $bucket['count']);
            $progressLabels[] = 'S'.$date->isoWeek.' '.$date->year;
            $progressReel[] = round((float) $bucket['sum_reel'] / $count, 2);
            $progressTheorique[] = round((float) $bucket['sum_theorique'] / $count, 2);
        }

        $trendRows = KpiMesure::query()
            ->select([
                'kpi_mesures.periode',
                'kpi_mesures.valeur',
                'kpis.cible',
                'kpis.seuil_alerte',
            ])
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpi_mesures.periode')
            ->orderBy('kpi_mesures.id');
        $this->scopeJoinedPta($trendRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $periodBuckets = [];
        foreach ($trendRows->get() as $row) {
            $period = trim((string) ($row->periode ?? ''));
            if ($period === '') {
                continue;
            }
            if (! isset($periodBuckets[$period])) {
                $periodBuckets[$period] = [
                    'sum_valeur' => 0.0,
                    'count_valeur' => 0,
                    'sum_cible' => 0.0,
                    'count_cible' => 0,
                    'sum_seuil' => 0.0,
                    'count_seuil' => 0,
                ];
            }
            $periodBuckets[$period]['sum_valeur'] += (float) ($row->valeur ?? 0);
            $periodBuckets[$period]['count_valeur']++;
            if ($row->cible !== null) {
                $periodBuckets[$period]['sum_cible'] += (float) $row->cible;
                $periodBuckets[$period]['count_cible']++;
            }
            if ($row->seuil_alerte !== null) {
                $periodBuckets[$period]['sum_seuil'] += (float) $row->seuil_alerte;
                $periodBuckets[$period]['count_seuil']++;
            }
        }

        $periodSortValue = static function (string $period): int {
            if (preg_match('/^(\d{4})-(\d{2})$/', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100 + (int) $matches[2];
            }
            if (preg_match('/^(\d{4})-T([1-4])$/i', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100 + ((int) $matches[2]) * 3;
            }
            if (preg_match('/^(\d{4})-S([1-2])$/i', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100 + ((int) $matches[2]) * 6;
            }
            if (preg_match('/^(\d{4})$/', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100;
            }

            return 0;
        };

        uksort($periodBuckets, function (string $left, string $right) use ($periodSortValue): int {
            $leftSort = $periodSortValue($left);
            $rightSort = $periodSortValue($right);
            if ($leftSort === $rightSort) {
                return strcmp($left, $right);
            }

            return $leftSort <=> $rightSort;
        });

        $periodBuckets = array_slice($periodBuckets, -12, 12, true);
        $trendLabels = [];
        $trendValues = [];
        $trendTargets = [];
        $trendThresholds = [];
        foreach ($periodBuckets as $period => $bucket) {
            $trendLabels[] = $period;
            $trendValues[] = round(
                $bucket['count_valeur'] > 0 ? ((float) $bucket['sum_valeur'] / (int) $bucket['count_valeur']) : 0,
                2
            );
            $trendTargets[] = round(
                $bucket['count_cible'] > 0 ? ((float) $bucket['sum_cible'] / (int) $bucket['count_cible']) : 0,
                2
            );
            $trendThresholds[] = round(
                $bucket['count_seuil'] > 0 ? ((float) $bucket['sum_seuil'] / (int) $bucket['count_seuil']) : 0,
                2
            );
        }

        $weekStarts = [];
        for ($i = 7; $i >= 0; $i--) {
            $weekStarts[] = (clone $today)->startOfWeek(Carbon::MONDAY)->subWeeks($i);
        }
        $weekKeys = array_map(fn (Carbon $date): string => $date->toDateString(), $weekStarts);
        $weekLabels = array_map(fn (Carbon $date): string => 'S'.$date->isoWeek, $weekStarts);

        $heatRows = Action::query()
            ->select(['actions.date_echeance', 'ptas.direction_id'])
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('actions.date_echeance')
            ->whereDate('actions.date_echeance', '<', $todayDate)
            ->whereDate('actions.date_echeance', '>=', $weekStarts[0]->toDateString())
            ->whereNotIn('actions.statut_dynamique', $completedStatuses);
        $this->scopeJoinedPta($heatRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $heatMatrixByDirection = [];
        $heatDirectionTotals = [];
        foreach ($heatRows->get() as $row) {
            $directionId = (int) ($row->direction_id ?? 0);
            if ($directionId <= 0 || $row->date_echeance === null) {
                continue;
            }
            $key = Carbon::parse((string) $row->date_echeance)->startOfWeek(Carbon::MONDAY)->toDateString();
            if (! in_array($key, $weekKeys, true)) {
                continue;
            }
            $heatMatrixByDirection[$directionId][$key] = (int) (($heatMatrixByDirection[$directionId][$key] ?? 0) + 1);
            $heatDirectionTotals[$directionId] = (int) (($heatDirectionTotals[$directionId] ?? 0) + 1);
        }
        arsort($heatDirectionTotals);
        $heatDirectionIds = array_slice(array_keys($heatDirectionTotals), 0, 8);
        if ($heatDirectionIds === [] && $user->direction_id !== null) {
            $heatDirectionIds = [(int) $user->direction_id];
        }
        $heatDirectionNames = Direction::query()
            ->pluck('libelle', 'id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->toArray();
        $heatUnits = array_map(
            fn (int $directionId): string => $heatDirectionNames[$directionId] ?? ('#'.$directionId),
            $heatDirectionIds
        );
        $heatMatrix = [];
        $heatMax = 0;
        foreach ($heatDirectionIds as $directionId) {
            $rowValues = [];
            foreach ($weekKeys as $weekKey) {
                $value = (int) ($heatMatrixByDirection[$directionId][$weekKey] ?? 0);
                $rowValues[] = $value;
                $heatMax = max($heatMax, $value);
            }
            $heatMatrix[] = $rowValues;
        }

        $actionCandidates = (clone $actionQuery)
            ->with([
                'pta:id,pao_id,titre,direction_id,service_id',
                'pta.pao:id,titre',
                'responsable:id,name,email',
            ])
            ->orderByDesc('id')
            ->limit(350)
            ->get();

        $scoredActions = $actionCandidates
            ->map(function (Action $action) use ($today): array {
                $start = $action->date_debut instanceof Carbon
                    ? $action->date_debut->copy()
                    : ($action->created_at instanceof Carbon ? $action->created_at->copy() : $today->copy()->subWeek());
                $end = $action->date_fin instanceof Carbon
                    ? $action->date_fin->copy()
                    : ($action->date_echeance instanceof Carbon ? $action->date_echeance->copy() : $start->copy()->addWeeks(2));
                if ($end->lt($start)) {
                    $end = $start->copy()->addDay();
                }
                $progress = round(max(0, min(100, (float) ($action->progression_reelle ?? 0))), 2);
                $score = round($this->computeActionRiskScore($action, $today), 2);

                return [
                    'action' => $action,
                    'label' => (string) $action->libelle,
                    'start' => $start,
                    'end' => $end,
                    'progress' => $progress,
                    'status' => (string) ($action->statut_dynamique ?? 'non_demarre'),
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->values();

        $criticalItems = $scoredActions->take(10)->values();
        $ganttMin = $criticalItems->isNotEmpty()
            ? $criticalItems->min(fn (array $item): int => $item['start']->getTimestamp())
            : $today->copy()->subDays(14)->getTimestamp();
        $ganttMax = $criticalItems->isNotEmpty()
            ? $criticalItems->max(fn (array $item): int => $item['end']->getTimestamp())
            : $today->copy()->addDays(14)->getTimestamp();
        if ($ganttMin === $ganttMax) {
            $ganttMax += 86400;
        }
        $criticalGantt = [
            'min' => Carbon::createFromTimestamp($ganttMin)->toDateString(),
            'max' => Carbon::createFromTimestamp($ganttMax)->toDateString(),
            'items' => $criticalItems
                ->map(fn (array $item): array => [
                    'label' => Str::limit($item['label'], 58),
                    'start' => $item['start']->toDateString(),
                    'end' => $item['end']->toDateString(),
                    'progress' => (float) $item['progress'],
                    'status' => (string) $item['status'],
                    'score' => (float) $item['score'],
                ])
                ->all(),
        ];

        $resourceTotals = [];
        foreach ($actionCandidates as $action) {
            $groupLabel = trim((string) ($action->pta?->pao?->titre ?? $action->pta?->titre ?? 'Sans axe'));
            if ($groupLabel === '') {
                $groupLabel = 'Sans axe';
            }
            $weight = 0.0;
            $weight += (bool) $action->ressource_main_oeuvre ? 1.0 : 0.0;
            $weight += (bool) $action->ressource_equipement ? 1.0 : 0.0;
            $weight += (bool) $action->ressource_partenariat ? 1.0 : 0.0;
            $weight += (bool) $action->ressource_autres ? 1.0 : 0.0;
            $weight += (bool) $action->financement_requis ? 2.0 : 0.0;
            $montant = (float) ($action->montant_estime ?? 0);
            if ($montant > 0) {
                $weight += min(10.0, $montant / 1000000);
            }
            if ($weight <= 0) {
                $weight = 0.5;
            }
            $resourceTotals[$groupLabel] = ($resourceTotals[$groupLabel] ?? 0.0) + $weight;
        }
        arsort($resourceTotals);
        $resourceTotals = array_slice($resourceTotals, 0, 12, true);
        $resourceLabels = array_keys($resourceTotals);
        $resourceValues = array_map(fn ($value): float => round((float) $value, 2), array_values($resourceTotals));

        $riskCounts = [];
        foreach ($actionCandidates as $action) {
            $riskText = (string) ($action->risques ?? '');
            if (trim($riskText) === '') {
                continue;
            }
            $parts = preg_split('/[;,|\/\r\n]+/', $riskText) ?: [];
            foreach ($parts as $part) {
                $normalized = $this->normalizeRiskLabel((string) $part);
                if ($normalized === null) {
                    continue;
                }
                $riskCounts[$normalized] = (int) (($riskCounts[$normalized] ?? 0) + 1);
            }
        }
        arsort($riskCounts);
        $riskCounts = array_slice($riskCounts, 0, 8, true);

        $paretoLabels = array_keys($riskCounts);
        $paretoValues = array_values($riskCounts);
        $paretoCumulative = [];
        $runningTotal = 0;
        $allTotal = array_sum($paretoValues);
        foreach ($paretoValues as $value) {
            $runningTotal += (int) $value;
            $paretoCumulative[] = $allTotal > 0 ? round(($runningTotal / $allTotal) * 100, 2) : 0.0;
        }

        $topRiskRows = $scoredActions
            ->take(10)
            ->map(function (array $item): array {
                /** @var Action $action */
                $action = $item['action'];

                return [
                    'action' => (string) $item['label'],
                    'score' => (float) $item['score'],
                    'statut' => (string) $item['status'],
                    'echeance' => $action->date_echeance instanceof Carbon ? $action->date_echeance->toDateString() : '-',
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                ];
            })
            ->all();

        $topRiskLabels = array_map(
            fn (array $row): string => Str::limit((string) $row['action'], 34),
            $topRiskRows
        );
        $topRiskScores = array_map(fn (array $row): float => (float) $row['score'], $topRiskRows);

        $performanceRows = Action::query()
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->selectRaw('ptas.direction_id as direction_id, AVG(COALESCE(actions.progression_reelle, 0)) as avg_progression, COUNT(*) as total')
            ->groupBy('ptas.direction_id');
        $this->scopeJoinedPta($performanceRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $performanceDirectionNames = Direction::query()
            ->pluck('libelle', 'id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->toArray();

        $perfRows = $performanceRows->get()->map(function ($row): array {
            return [
                'direction_id' => (int) ($row->direction_id ?? 0),
                'avg' => round(max(0, min(100, (float) ($row->avg_progression ?? 0))), 2),
                'total' => (int) ($row->total ?? 0),
            ];
        })->sortByDesc('total')->values();

        $performanceLabels = [];
        $performanceValues = [];
        foreach ($perfRows->take(6) as $row) {
            $directionId = (int) $row['direction_id'];
            if ($directionId <= 0) {
                continue;
            }
            $performanceLabels[] = $performanceDirectionNames[$directionId] ?? ('#'.$directionId);
            $performanceValues[] = (float) $row['avg'];
        }

        if ($performanceLabels === [] && $user->direction_id !== null) {
            $performanceLabels[] = $performanceDirectionNames[(int) $user->direction_id] ?? ('#'.$user->direction_id);
            $performanceValues[] = 0.0;
        }

        $interannualRows = $this->buildInterannualComparison($user);

        return [
            'funnel' => $funnel,
            'status_by_unit' => [
                'unit_label' => $unitLabel,
                'labels' => $statusLabels,
                'datasets' => $statusDatasets,
            ],
            'progress_weekly' => [
                'labels' => $progressLabels,
                'reel' => $progressReel,
                'theorique' => $progressTheorique,
            ],
            'kpi_trend' => [
                'labels' => $trendLabels,
                'valeurs' => $trendValues,
                'cibles' => $trendTargets,
                'seuils' => $trendThresholds,
            ],
            'retard_heatmap' => [
                'weeks' => $weekLabels,
                'units' => $heatUnits,
                'matrix' => $heatMatrix,
                'max' => $heatMax,
            ],
            'critical_gantt' => $criticalGantt,
            'resource_treemap' => [
                'labels' => array_map(fn ($label): string => Str::limit((string) $label, 44), $resourceLabels),
                'values' => $resourceValues,
                'total' => round((float) array_sum($resourceValues), 2),
            ],
            'risk_pareto' => [
                'labels' => array_map(
                    fn ($label): string => Str::limit(Str::title((string) $label), 42),
                    $paretoLabels
                ),
                'counts' => $paretoValues,
                'cumulative_pct' => $paretoCumulative,
            ],
            'top_risks' => [
                'labels' => $topRiskLabels,
                'scores' => $topRiskScores,
                'rows' => $topRiskRows,
            ],
            'performance_gauge' => [
                'labels' => $performanceLabels,
                'values' => $performanceValues,
            ],
            'interannual_overview' => [
                'labels' => array_map(fn (array $row): string => (string) $row['annee'], $interannualRows),
                'actions_total' => array_map(fn (array $row): int => (int) $row['actions_total'], $interannualRows),
                'actions_validees' => array_map(fn (array $row): int => (int) $row['actions_validees'], $interannualRows),
                'progression_moyenne' => array_map(fn (array $row): float => (float) $row['progression_moyenne'], $interannualRows),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function completedActionStatuses(): array
    {
        return [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_SUSPENDU,
            ActionTrackingService::STATUS_ANNULE,
        ];
    }

    private function computeActionRiskScore(Action $action, Carbon $today): float
    {
        $lateDays = 0;
        if (
            $action->date_echeance instanceof Carbon
            && ! in_array((string) $action->statut_dynamique, $this->completedActionStatuses(), true)
            && $action->date_echeance->lt($today)
        ) {
            $lateDays = $action->date_echeance->diffInDays($today);
        }

        $gap = max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));
        $hasRisk = trim((string) ($action->risques ?? '')) !== '';
        $isDelayed = (string) ($action->statut_dynamique ?? '') === 'en_retard';

        $score = ($lateDays * 2.0) + $gap;
        if ($isDelayed) {
            $score += 25.0;
        }
        if ($hasRisk) {
            $score += 10.0;
        }
        if ((bool) $action->financement_requis) {
            $score += 4.0;
        }

        return max(0.0, $score);
    }

    private function normalizeRiskLabel(string $value): ?string
    {
        $normalized = Str::of($value)
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim(" \t\n\r\0\x0B-._");

        $label = (string) $normalized;
        if ($label === '' || Str::length($label) < 4) {
            return null;
        }

        $ignored = ['ras', 'aucun', 'aucune', 'neant', 'n/a', 'na', 'none', 'ok'];
        if (in_array($label, $ignored, true)) {
            return null;
        }

        return $label;
    }

    /**
     * @return array{
     *     generatedAt: \Illuminate\Support\Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     global: array<string, int>,
     *     statuts: array<string, array<string, int>>,
     *     alertes: array<string, int>,
     *     charts: array<string, mixed>,
     *     details: array{
     *         actions_retard: \Illuminate\Support\Collection<int, \App\Models\Action>,
     *         kpi_sous_seuil: \Illuminate\Support\Collection<int, \App\Models\KpiMesure>,
     *         structure_rapports: \Illuminate\Support\Collection<int, array<string, string>>
     *     }
     * }
     */
    private function buildReportingPayload(User $user, bool $withDetails = false, bool $withCharts = true): array
    {
        $today = Carbon::today()->toDateString();
        $pas = $this->buildPasScopedQuery($user);

        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $mesures = KpiMesure::query();
        $objectifsOperationnels = PaoObjectifOperationnel::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeMesure($mesures, $user);
        $this->scopeObjectifOperationnel($objectifsOperationnels, $user);
        $actionsStatistics = (clone $actions);
        $this->scopeActionStatistics($actionsStatistics);

        $retardsActions = (clone $actions)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
            ->count();

        $kpiSousSeuilQuery = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');
        $this->scopeJoinedPta($kpiSousSeuilQuery, $user, 'ptas.direction_id', 'ptas.service_id');

        $details = [
            'actions_retard' => collect(),
            'kpi_sous_seuil' => collect(),
            'structure_rapports' => collect(),
        ];

        if ($withDetails) {
            $details['actions_retard'] = (clone $actions)
                ->with(['pta:id,titre,direction_id,service_id', 'responsable:id,name,email'])
                ->whereNotNull('date_echeance')
                ->whereDate('date_echeance', '<', $today)
                ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
                ->orderBy('date_echeance')
                ->limit(200)
                ->get();

            $kpiIds = (clone $kpiSousSeuilQuery)
                ->select('kpi_mesures.id')
                ->orderByDesc('kpi_mesures.id')
                ->limit(200)
                ->pluck('kpi_mesures.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $details['kpi_sous_seuil'] = KpiMesure::query()
                ->with([
                    'kpi:id,action_id,libelle,seuil_alerte,periodicite',
                    'kpi.action:id,libelle,pta_id',
                    'kpi.action.pta:id,titre,direction_id,service_id',
                ])
                ->whereIn('id', $kpiIds)
                ->orderByDesc('id')
                ->get();

            $details['structure_rapports'] = (clone $actions)
                ->with([
                    'pta:id,pao_id,titre,direction_id,service_id',
                    'pta.pao:id,titre',
                    'responsable:id,name,email',
                    'kpis:id,action_id,libelle',
                ])
                ->orderByDesc('id')
                ->limit(300)
                ->get()
                ->map(function (Action $action): array {
                    $ressources = [];
                    if ((bool) $action->ressource_main_oeuvre) {
                        $ressources[] = 'Main d oeuvre';
                    }
                    if ((bool) $action->ressource_equipement) {
                        $ressources[] = 'Equipement';
                    }
                    if ((bool) $action->ressource_partenariat) {
                        $ressources[] = 'Partenariat';
                    }
                    if ((bool) $action->ressource_autres) {
                        $details = trim((string) ($action->ressource_autres_details ?? ''));
                        $ressources[] = $details !== '' ? 'Autres: '.$details : 'Autres';
                    }
                    if ((bool) $action->financement_requis) {
                        $source = trim((string) ($action->source_financement ?? ''));
                        $ressources[] = $source !== '' ? 'Financement: '.$source : 'Financement';
                    }

                    $cible = '';
                    if ($action->type_cible === 'quantitative') {
                        $quantite = $action->quantite_cible !== null
                            ? number_format((float) $action->quantite_cible, 2, '.', '')
                            : '';
                        $unite = trim((string) ($action->unite_cible ?? ''));
                        $cible = trim($quantite.' '.$unite);
                    } else {
                        $cible = trim((string) ($action->resultat_attendu ?: $action->livrable_attendu ?: ''));
                    }

                    $indicateurs = $action->kpis
                        ->pluck('libelle')
                        ->filter(fn ($label): bool => trim((string) $label) !== '')
                        ->values()
                        ->implode(' | ');

                    return [
                        'axe_strategique' => (string) ($action->pta?->pao?->titre ?? $action->pta?->titre ?? ''),
                        'objectif_strategique' => (string) ($action->pta?->titre ?? ''),
                        'objectif_operationnel' => (string) $action->libelle,
                        'description_actions_detaillees' => (string) ($action->description ?? ''),
                        'rmo' => (string) ($action->responsable?->name ?? ''),
                        'cible' => (string) $cible,
                        'debut' => optional($action->date_debut)->format('Y-m-d') ?? '',
                        'fin' => optional($action->date_fin)->format('Y-m-d') ?? '',
                        'etat_realisation' => (string) $action->statut_dynamique,
                        'progression' => number_format((float) ($action->progression_reelle ?? 0), 2, '.', '').'%',
                        'ressources_requises' => implode(' | ', $ressources),
                        'indicateurs_performance' => (string) $indicateurs,
                        'risques_potentiels' => (string) ($action->risques ?? ''),
                    ];
                });
        }

        return [
            'generatedAt' => now(),
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
            ],
            'global' => [
                'pas_total' => (clone $pas)->count(),
                'paos_total' => (clone $paos)->count(),
                'ptas_total' => (clone $ptas)->count(),
                'actions_total' => (clone $actions)->count(),
                'actions_validees' => (clone $actionsStatistics)->count(),
                'kpi_mesures_total' => (clone $mesures)->count(),
                'objectifs_operationnels_total' => (clone $objectifsOperationnels)->count(),
            ],
            'statuts' => [
                'pas' => $this->countByStatus($pas, 'statut'),
                'paos' => $this->countByStatus($paos, 'statut'),
                'ptas' => $this->countByStatus($ptas, 'statut'),
                'actions' => $this->countByStatus($actions, 'statut_dynamique'),
                'actions_validation' => $this->countByStatus($actions, 'statut_validation'),
                'objectifs_operationnels' => $this->countByStatus($objectifsOperationnels, 'statut_realisation'),
            ],
            'alertes' => [
                'actions_en_retard' => $retardsActions,
                'mesures_kpi_sous_seuil' => $kpiSousSeuilQuery->count(),
            ],
            'pasConsolidation' => $this->buildPasConsolidation($user),
            'interannualComparison' => $this->buildInterannualComparison($user),
            'charts' => $withCharts ? $this->buildChartsPayload($user) : [],
            'details' => $details,
        ];
    }

    private function markAlertNotificationsAsRead(User $user): void
    {
        $user->unreadNotifications()
            ->get()
            ->filter(static fn ($notification): bool => strtolower((string) ($notification->data['module'] ?? '')) === 'alertes')
            ->each
            ->markAsRead();
    }
}
