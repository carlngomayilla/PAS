<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\ActionWeek;
use App\Models\Direction;
use App\Models\ExportTemplate;
use App\Models\ActionKpi;
use App\Models\Justificatif;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\PasAxe;
use App\Models\PaoObjectifOperationnel;
use App\Models\PaoObjectifStrategique;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Exports\ExportTemplateResolver;
use App\Services\ExerciceContext;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Exports\ReportingWorkbookExporter;
use App\Support\SafeSql;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitoringWebController extends Controller
{
    use AuthorizesPlanningScope;

    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly AlertCenterService $alertCenter,
        private readonly AlertReadService $alertReadService,
        private readonly ReportingWorkbookExporter $reportingWorkbookExporter,
        private readonly ReportingAnalyticsService $reportingAnalyticsService,
        private readonly ExportTemplateResolver $exportTemplateResolver,
        private readonly ActionTrackingService $actionTrackingService
    ) {
    }

    public function pilotage(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);
        $this->denyUnlessReportingReader($user);
        $roleProfile = $this->buildMonitoringRoleProfile($user, 'pilotage');

        $filterDirectionId = $user->hasGlobalReadAccess() ? ((int) $request->integer('direction_id') ?: null) : null;
        $filterServiceId = (int) $request->integer('service_id') ?: null;
        $filterPasId = (int) $request->integer('pas_id') ?: null;

        $today = Carbon::today()->toDateString();

        $pas = $this->buildPasScopedQuery($user);
        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $kpis = Kpi::query();
        $mesures = KpiMesure::query();
        $objectifsOperationnels = PaoObjectifOperationnel::query();
        $objectifsStrategiques = PaoObjectifStrategique::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeMesure($mesures, $user);
        $this->scopeObjectifOperationnel($objectifsOperationnels, $user);
        $this->scopeObjectifStrategique($objectifsStrategiques, $user);

        if ($filterDirectionId !== null) {
            $paos->where('direction_id', $filterDirectionId);
            $ptas->where('direction_id', $filterDirectionId);
            $actions->whereHas('pta', fn (Builder $q) => $q->where('direction_id', $filterDirectionId));
        }
        if ($filterServiceId !== null) {
            $ptas->where('service_id', $filterServiceId);
            $actions->whereHas('pta', fn (Builder $q) => $q->where('service_id', $filterServiceId));
        }
        if ($filterPasId !== null) {
            $paos->where('pas_id', $filterPasId);
            $ptas->whereHas('pao', fn (Builder $q) => $q->where('pas_id', $filterPasId));
            $actions->whereHas('pta.pao', fn (Builder $q) => $q->where('pas_id', $filterPasId));
        }

        $actionsStatistics = (clone $actions);
        $this->scopeActionStatistics($actionsStatistics);

        $totals = [
            'pas_total' => (clone $pas)->count(),
            'paos_total' => (clone $paos)->count(),
            'ptas_total' => (clone $ptas)->count(),
            'objectifs_strategiques_total' => (clone $objectifsStrategiques)->count(),
            'objectifs_operationnels_total' => (clone $objectifsOperationnels)->count(),
            'actions_total' => (clone $actions)->count(),
            'actions_validees' => (clone $actionsStatistics)->count(),
            'kpis_total' => (clone $kpis)->count(),
            'kpi_mesures_total' => (clone $mesures)->count(),
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

        // --- Métriques enrichies ---
        $axesTotal = PasAxe::query()
            ->whereHas('pas', fn (Builder $q) => $this->buildPasScopedSubQuery($q, $user))
            ->count();

        $actionsEnCours = (clone $actions)->where('statut_dynamique', 'en_cours')->count();
        $actionsACorriger = (clone $actions)->where('statut_dynamique', 'a_corriger')->count();
        $actionsRejetees = (clone $actions)->whereIn('statut_validation', ['rejetee_chef', 'rejetee_direction'])->count();
        $actionsCloturees = (clone $actions)->where('statut_dynamique', 'cloturee')->count();
        $actionsAcheveDansDelai = (clone $actions)->where('statut_dynamique', 'acheve_dans_delai')->count();
        $actionsAcheveHorsDelai = (clone $actions)->where('statut_dynamique', 'acheve_hors_delai')->count();

        $validationsEnAttente = (clone $actions)
            ->whereIn('statut_validation', ['soumise_chef', 'validee_chef'])
            ->count();

        $justificatifsManquants = (clone $actions)
            ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
            ->whereDoesntHave('justificatifs')
            ->where(function (Builder $q): void {
                $q->whereDate('date_echeance', '<=', now()->addDays(14)->toDateString())
                    ->orWhere('statut_validation', 'soumise_chef');
            })
            ->count();

        $tauxKpis = ActionKpi::query()
            ->join('actions', 'actions.id', '=', 'action_kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id');
        $this->scopeJoinedPta($tauxKpis, $user, 'ptas.direction_id', 'ptas.service_id');
        $tauxMoyens = $tauxKpis->selectRaw('
            AVG(action_kpis.kpi_performance) as avg_performance,
            AVG(action_kpis.kpi_conformite) as avg_conformite,
            AVG(action_kpis.kpi_delai) as avg_delai,
            AVG(action_kpis.kpi_global) as avg_global
        ')->first();

        $actionsRetardDetails = (clone $actions)
            ->with(['pta:id,titre,direction_id,service_id', 'pta.direction:id,code,libelle', 'pta.service:id,code,libelle', 'responsable:id,name'])
            ->where(function (Builder $q) use ($today): void {
                $q->where('statut_dynamique', 'en_retard')
                    ->orWhere(function (Builder $sq) use ($today): void {
                        $sq->whereNotNull('date_echeance')
                            ->whereDate('date_echeance', '<', $today)
                            ->whereNotIn('statut_dynamique', $this->completedActionStatuses());
                    });
            })
            ->orderBy('date_echeance')
            ->limit(20)
            ->get();

        $validationsDetails = (clone $actions)
            ->with(['pta:id,titre,direction_id,service_id', 'pta.direction:id,code,libelle', 'responsable:id,name', 'soumisPar:id,name'])
            ->whereIn('statut_validation', ['soumise_chef', 'validee_chef'])
            ->orderBy('soumise_le')
            ->limit(20)
            ->get();

        $ruptures = [
            ['type' => 'PAO sans PTA', 'count' => $paoSansPta, 'niveau' => 'PAO', 'lien' => route('workspace.pao.index', ['without_pta' => 1])],
            ['type' => 'PTA sans action', 'count' => $ptaSansAction, 'niveau' => 'PTA', 'lien' => route('workspace.pta.index', ['without_action' => 1])],
            ['type' => 'Action sans indicateur', 'count' => $actionSansKpi, 'niveau' => 'Action', 'lien' => route('workspace.actions.index', ['without_kpi' => 1])],
            ['type' => 'Indicateur sans mesure', 'count' => $kpiSansMesure, 'niveau' => 'Indicateur', 'lien' => route('workspace.reporting')],
        ];

        $decisionPoints = $this->buildDecisionPoints(
            $actionsRetard, $actionsACorriger, $actionsRejetees,
            (int) round((float) ($tauxMoyens?->avg_global ?? 0)),
            $kpiSousSeuilQuery->count(), $validationsEnAttente
        );

        // A32 — Anciennes 4 sous-requetes correlees par PAS remplacees par
        // des LEFT JOINs + GROUP BY. Sur PostgreSQL le query planner peut
        // executer 4*N sous-requetes (N=PAS), ici on tombe a 1 seule passe.
        // Cleanup important : on garde un clone du builder source (qui porte
        // deja le scope user) puis on overrride completement le SELECT/JOIN.
        $chaineSummary = (clone $pas)
            ->select([
                'pas.id',
                'pas.titre',
                'pas.statut',
                'pas.periode_debut',
                'pas.periode_fin',
            ])
            ->selectRaw('COUNT(DISTINCT paos.id) as pao_count')
            ->selectRaw('COUNT(DISTINCT ptas.id) as pta_count')
            ->selectRaw('COUNT(DISTINCT actions.id) as action_count')
            ->selectRaw('ROUND(COALESCE(AVG(actions.taux_realisation_global), 0), 1) as taux_moyen')
            ->leftJoin('paos', 'paos.pas_id', '=', 'pas.id')
            ->leftJoin('ptas', 'ptas.pao_id', '=', 'paos.id')
            ->leftJoin('actions', 'actions.pta_id', '=', 'ptas.id')
            ->groupBy('pas.id', 'pas.titre', 'pas.statut', 'pas.periode_debut', 'pas.periode_fin')
            ->orderByDesc('pas.periode_debut')
            ->limit(10)
            ->get();

        $kpiSousSeuilDetailsQuery = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte')
            ->select([
                'kpi_mesures.id',
                'kpis.libelle as kpi_libelle',
                'kpis.unite as kpi_unite',
                'kpis.seuil_alerte',
                'kpi_mesures.valeur as valeur_realisee',
                'kpi_mesures.periode',
                'actions.id as action_id',
                'actions.libelle as action_libelle',
                'actions.statut_dynamique',
            ])
            ->selectRaw('(kpis.seuil_alerte - kpi_mesures.valeur) as ecart');
        $this->scopeJoinedPta($kpiSousSeuilDetailsQuery, $user, 'ptas.direction_id', 'ptas.service_id');
        if ($filterDirectionId !== null) {
            $kpiSousSeuilDetailsQuery->where('ptas.direction_id', $filterDirectionId);
        }
        if ($filterServiceId !== null) {
            $kpiSousSeuilDetailsQuery->where('ptas.service_id', $filterServiceId);
        }
        $kpiSousSeuilDetails = $kpiSousSeuilDetailsQuery
            ->orderByRaw('(kpis.seuil_alerte - kpi_mesures.valeur) DESC')
            ->limit(20)
            ->get();

        $justificatifsManquantsDetails = (clone $actions)
            ->with(['pta:id,titre,direction_id,service_id', 'pta.direction:id,code,libelle', 'responsable:id,name'])
            ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
            ->whereDoesntHave('justificatifs')
            ->where(function (Builder $q): void {
                $q->whereDate('date_echeance', '<=', now()->addDays(14)->toDateString())
                    ->orWhere('statut_validation', 'soumise_chef');
            })
            ->orderBy('date_echeance')
            ->limit(20)
            ->get();

        $directionOptions = Direction::query()
            ->where('actif', true)
            ->orderBy('code')
            ->get(['id', 'code', 'libelle']);
        $pasOptions = (clone $pas)->orderByDesc('periode_debut')->get(['id', 'titre']);
        $pilotageFilters = [
            'direction_id' => $filterDirectionId,
            'service_id'   => $filterServiceId,
            'pas_id'       => $filterPasId,
        ];

        $chartsPayload = $this->reportingAnalyticsService
            ->buildPayload($user, false, true)['charts'] ?? [];

        return view('workspace.monitoring.pilotage', [
            'generatedAt' => now(),
            'roleProfile' => $roleProfile,
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
            ],
            'statisticalPolicy' => [
                'scope_status' => $this->actionCalculationSettings->statisticalScope(),
                'scope_label' => $this->actionCalculationSettings->statisticalScopeLabel(),
                'scope_summary' => $this->actionCalculationSettings->statisticalScopeSummary(),
                'route_filters' => $this->actionCalculationSettings->statisticalRouteFilters(),
            ],
            'officialPolicy' => [
                'threshold_status' => $this->actionCalculationSettings->statisticalScope(),
                'threshold_label' => $this->actionCalculationSettings->statisticalScopeLabel(),
                'scope_summary' => $this->actionCalculationSettings->statisticalScopeSummary(),
                'route_filters' => $this->actionCalculationSettings->statisticalRouteFilters(),
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
            'axesTotal' => $axesTotal,
            'actionsStatutDetails' => [
                'en_cours' => $actionsEnCours,
                'acheve_dans_delai' => $actionsAcheveDansDelai,
                'acheve_hors_delai' => $actionsAcheveHorsDelai,
                'en_retard' => $actionsRetard,
                'a_corriger' => $actionsACorriger,
                'rejetees' => $actionsRejetees,
                'cloturees' => $actionsCloturees,
            ],
            'tauxMoyens' => [
                'performance' => round((float) ($tauxMoyens?->avg_performance ?? 0), 1),
                'conformite' => round((float) ($tauxMoyens?->avg_conformite ?? 0), 1),
                'delai' => round((float) ($tauxMoyens?->avg_delai ?? 0), 1),
                'global' => round((float) ($tauxMoyens?->avg_global ?? 0), 1),
            ],
            'validationsEnAttente' => $validationsEnAttente,
            'justificatifsManquants' => $justificatifsManquants,
            'actionsRetardDetails' => $actionsRetardDetails,
            'validationsDetails' => $validationsDetails,
            'rupturesDetail' => $ruptures,
            'decisionPoints' => $decisionPoints,
            'chartsPayload' => $chartsPayload,
            'chaineSummary' => $chaineSummary,
            'kpiSousSeuilDetails' => $kpiSousSeuilDetails,
            'justificatifsManquantsDetails' => $justificatifsManquantsDetails,
            'directionOptions' => $directionOptions,
            'pasOptions' => $pasOptions,
            'pilotageFilters' => $pilotageFilters,
        ]);
    }

    public function reporting(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);
        $this->denyUnlessReportingReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user, true, false);
        $payload['roleProfile'] = $this->buildMonitoringRoleProfile($user, 'reporting');
        $payload['dgComparison'] = ($payload['roleProfile']['role'] ?? null) === 'dg'
            ? $this->buildDgMonitoringComparison($user)
            : null;
        $payload['activeExportTemplates'] = [
            'excel' => $this->resolveReportingExportTemplate($user, 'excel')?->name,
            'pdf' => $this->resolveReportingExportTemplate($user, 'pdf')?->name,
        ];

        return view('workspace.monitoring.reporting', $payload);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);
        $this->denyUnlessReportingReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user, true, true);
        $template = $this->resolveReportingExportTemplate($user, 'excel');
        if ($template !== null) {
            $payload['export_template'] = [
                'name' => $template->name,
                'title' => $template->documentTitle(),
                'subtitle' => $template->documentSubtitle(),
                'filename_prefix' => $template->filenamePrefix(),
                'layout' => $template->layout_config ?? [],
                'blocks' => $template->blocks_config ?? [],
            ];
        }
        $generatedAt = $payload['generatedAt'];
        $filenamePrefix = $template?->filenamePrefix() ?? 'reporting_anbg';
        $filename = $this->institutionalReportFilename('REPORTING', $payload, 'xlsx', $filenamePrefix);
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
        $this->denyUnlessAlertReader($user);

        $payload = $this->reportingAnalyticsService->buildPayload($user, true, true);
        $template = $this->resolveReportingExportTemplate($user, 'pdf');
        if ($template !== null) {
            $payload['exportTemplate'] = $template;
        }
        $generatedAt = $payload['generatedAt'];
        $filenamePrefix = $template?->filenamePrefix() ?? 'reporting_anbg';
        $filename = $this->institutionalReportFilename('REPORTING', $payload, 'pdf', $filenamePrefix);

        @ini_set('memory_limit', '512M');

        return Pdf::loadView('workspace.monitoring.reporting-pdf', $payload)
            ->setPaper($template?->paperSize() ?? 'a4', $template?->orientation() ?? 'landscape')
            ->download($filename);
    }

    public function queueExport(Request $request, string $format): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $format = strtolower($format);
        if (! in_array($format, ['excel', 'pdf'], true)) {
            abort(404);
        }

        $this->denyUnlessPlanningReader($user);
        $this->denyUnlessReportingReader($user);

        GenerateReportJob::dispatch((int) $user->id, $format);

        return back()->with('success', 'Export '.$format.' lance. Une notification contiendra le lien de telechargement.');
    }

    public function downloadQueuedExport(Request $request): StreamedResponse
    {
        // A03 — Anti-fuite horizontale d exports :
        // 1) Authentification obligatoire (sinon une URL signee rejouee par un
        //    visiteur anonyme telechargerait n importe quel export).
        // 2) Ownership : le chemin doit pointer dans le dossier de l utilisateur
        //    courant. Les exports sont stockes sous exports/reporting/{user_id}/...
        //    (cf. GenerateReportJob::handle()), donc on verifie le prefixe.
        // 3) Defense en profondeur path-traversal (le decryptString controle deja
        //    l integrite, mais on rejette explicitement les .. parasites).
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $path = Crypt::decryptString((string) $request->query('path'));
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            abort(403, 'Lien de telechargement invalide.');
        }

        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalizedPath === '' || str_contains($normalizedPath, '..')) {
            abort(403, 'Chemin d export invalide.');
        }

        $expectedPrefix = 'exports/reporting/'.(int) $user->id.'/';
        if (! Str::startsWith($normalizedPath, $expectedPrefix)) {
            abort(403, 'Acces non autorise a cet export.');
        }

        if (! Storage::disk('local')->exists($normalizedPath)) {
            abort(404);
        }

        $filename = trim((string) $request->query('name')) ?: basename($normalizedPath);
        $contentType = trim((string) $request->query('content_type')) ?: 'application/octet-stream';

        return Storage::disk('local')->download($normalizedPath, $filename, [
            'Content-Type' => $contentType,
        ]);
    }
    private function resolveReportingExportTemplate(User $user, string $format): ?ExportTemplate
    {
        return $this->exportTemplateResolver->resolve($user, 'reporting', 'consolidated_reporting', $format, 'officiel');
    }

    private function institutionalReportFilename(string $type, array $payload, string $extension, ?string $prefix = null): string
    {
        $generatedAt = $payload['generatedAt'] instanceof Carbon
            ? $payload['generatedAt']
            : now();
        $scope = (array) ($payload['scope'] ?? []);
        $prefixToken = $this->filenameToken((string) ($prefix ?: ''), '');
        $directionToken = $this->directionFilenameToken($scope['direction_id'] ?? null);
        $serviceToken = $this->serviceFilenameToken($scope['service_id'] ?? null);

        $parts = ['RAPPORT'];
        $parts[] = $this->filenameToken($type, 'REPORTING');
        $parts[] = $directionToken;
        $parts[] = $serviceToken;
        if ($prefixToken !== '' && Str::upper($prefixToken) !== 'RAPPORT' && $prefixToken !== 'reporting_anbg') {
            $parts[] = $prefixToken;
        }
        $parts[] = $generatedAt->format('Ymd_His');

        return implode('_', $parts).'.'.$this->filenameToken($extension, 'dat');
    }

    private function directionFilenameToken(mixed $directionId): string
    {
        if ($directionId === null || $directionId === '') {
            return 'GLOBAL';
        }

        $direction = Direction::query()->find((int) $directionId, ['id', 'code', 'libelle']);

        return $this->filenameToken((string) ($direction?->code ?: $direction?->libelle ?: 'DIRECTION_'.$directionId), 'DIRECTION_'.$directionId);
    }

    private function serviceFilenameToken(mixed $serviceId): string
    {
        if ($serviceId === null || $serviceId === '') {
            return 'GLOBAL';
        }

        $service = Service::query()->find((int) $serviceId, ['id', 'code', 'libelle']);

        return $this->filenameToken((string) ($service?->code ?: $service?->libelle ?: 'SERVICE_'.$serviceId), 'SERVICE_'.$serviceId);
    }

    private function filenameToken(string $value, string $fallback): string
    {
        $token = (string) Str::of($value)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_');

        return $token !== '' ? $token : $fallback;
    }

    public function alertes(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);
        $this->denyUnlessAlertReader($user);

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
        $this->denyUnlessAlertReader($user);

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
        $this->denyUnlessAlertReader($user);

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
        app(ExerciceContext::class)->applyToPao($query);
        $this->scopeByUserDirection($query, $user, 'direction_id');
    }

    private function scopePta(Builder|Relation $query, User $user): void
    {
        app(ExerciceContext::class)->applyToPta($query);
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
    }

    private function scopeAction(Builder|Relation $query, User $user): void
    {
        app(ExerciceContext::class)->applyToAction($query);

        if ($user->hasGlobalReadAccess()) {
            return;
        }

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    private function scopeActionStatistics(Builder $query, string $column = 'statut_validation'): void
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
                $actionsValidees = $this->officialActions($actions)->count();
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
            $actionsValidees = $this->officialActions($actions)->count();

            $axes = $pas->axes->map(function ($axe) use ($directionScopeIds, $paosByObjectif): array {
                $objectifs = $axe->objectifs->map(function ($objectif) use ($directionScopeIds, $paosByObjectif): array {
                    $objectifPaos = $paosByObjectif->get((int) $objectif->id, collect());
                    $objectifActions = $objectifPaos->flatMap->ptas->flatMap->actions;
                    $objectifActionsTotal = $objectifActions->count();
                    $objectifActionsValidees = $this->officialActions($objectifActions)->count();
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
        app(ExerciceContext::class)->applyToKpi($query);

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
        app(ExerciceContext::class)->applyToMesure($query);

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

    private function buildPasScopedSubQuery(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }
        if ($user->direction_id !== null) {
            $query->whereHas('directions', function (Builder $q) use ($user): void {
                $q->where('directions.id', (int) $user->direction_id);
            });
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @return list<array{titre: string, impact: string, responsable: string, urgence: string}>
     */
    private function buildDecisionPoints(
        int $retard, int $aCorriger, int $rejetes,
        int $tauxGlobal, int $kpiSousSeuil, int $validationsEnAttente
    ): array {
        $points = [];

        if ($retard > 0) {
            $points[] = [
                'titre' => $retard.' action(s) en retard',
                'impact' => 'Risque de non-atteinte des objectifs opérationnels',
                'responsable' => 'Directeurs / Chefs de service',
                'decision' => 'Mobiliser les responsables et redéfinir les priorités',
                'urgence' => 'haute',
            ];
        }
        if ($kpiSousSeuil > 0) {
            $points[] = [
                'titre' => $kpiSousSeuil.' indicateur(s) sous-seuil',
                'impact' => 'Performance insuffisante sur les objectifs mesurés',
                'responsable' => 'Responsables d\'action',
                'decision' => 'Analyser les écarts et corriger les plans d\'exécution',
                'urgence' => 'haute',
            ];
        }
        if ($validationsEnAttente > 0) {
            $points[] = [
                'titre' => $validationsEnAttente.' validation(s) en attente',
                'impact' => 'Actions bloquées dans le circuit de validation',
                'responsable' => 'Chefs de service / Directeurs',
                'decision' => 'Traiter les dossiers en attente de validation',
                'urgence' => 'moyenne',
            ];
        }
        if ($aCorriger > 0) {
            $points[] = [
                'titre' => $aCorriger.' action(s) à corriger',
                'impact' => 'Actions rejetées nécessitant une révision',
                'responsable' => 'Agents responsables',
                'decision' => 'Corriger et resoumettre les actions rejetées',
                'urgence' => 'moyenne',
            ];
        }
        if ($tauxGlobal < 50 && $tauxGlobal > 0) {
            $points[] = [
                'titre' => 'Taux global d\'avancement faible ('.$tauxGlobal.'%)',
                'impact' => 'Risque de non-réalisation des objectifs du plan',
                'responsable' => 'Direction Générale',
                'decision' => 'Revue urgente du plan d\'exécution',
                'urgence' => 'haute',
            ];
        }

        return $points;
    }

    private function scopeObjectifStrategique(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('paoAxe.pao', function (Builder $subQuery) use ($user): void {
                $subQuery->where('direction_id', (int) $user->direction_id);
            });
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('paoAxe.pao.ptas', function (Builder $subQuery) use ($user): void {
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
        $ptaTable = str_contains($directionColumn, '.') ? Str::before($directionColumn, '.') : 'ptas';
        app(ExerciceContext::class)->applyToJoinedPta($query, null, $ptaTable);

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

        app(ExerciceContext::class)->applyToPas($query);
        $this->scopePasByUser($query, $user);

        return $query;
    }

    private function countPasSansPao(User $user): int
    {
        $pasRows = $this->buildPasScopedQuery($user)
            ->with([
                'axes.objectifs.paos:id,pas_objectif_id,direction_id',
                'axes.objectifs.paos.ptas:id,pao_id,service_id',
            ])
            ->get();

        $scopedDirectionIds = $this->scopedDirectionIds($user);

        return $pasRows->filter(function (Pas $pas) use ($user, $scopedDirectionIds): bool {
            $expectedDirectionIds = $user->hasGlobalReadAccess()
                ? $scopedDirectionIds
                : (($user->direction_id !== null)
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
        $officialRows = $this->officialActions($rows);

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
                $officialRows = $this->officialActions($rows);
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
                    'subtitle' => 'Vue consolidée des volumes, ruptures de chaîne, retards et réalisation par année.',
                ],
                'service' => [
                    'eyebrow' => 'Pilotage service',
                    'title' => 'Suivi du service',
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
                    'subtitle' => 'Vue stratégique haute des plans, des ruptures et des tensions majeures du portefeuille.',
                ],
                'cabinet' => [
                    'eyebrow' => 'Pilotage cabinet',
                    'title' => 'Suivi transversal pour arbitrage',
                    'subtitle' => 'Lecture rapprochee des retards, ruptures et actions sensibles utiles a l appui decisionnel.',
                ],
            ],
            'reporting' => [
                'default' => [
                    'eyebrow' => 'Reporting consolidé',
                    'title' => "Centre d'export et de diffusion",
                    'subtitle' => "Les graphes et les tableaux de reporting ont été centralisés dans le dashboard analytique et servent ici à l'export et à la diffusion.",
                ],
                'service' => [
                    'eyebrow' => 'Reporting service',
                    'title' => 'Diffusion consolidée du service',
                    'subtitle' => 'Exports et vues consolidées du service avec lecture rapide des validations et de la performance.',
                ],
                'direction' => [
                    'eyebrow' => 'Reporting direction',
                    'title' => 'Centre de diffusion directionnel',
                    'subtitle' => 'Exports et lectures consolidées de la direction sans filtre statistique sur la validation.',
                ],
                'dg' => [
                    'eyebrow' => 'Reporting DG',
                    'title' => "Centre d'export institutionnel",
                    'subtitle' => "Point unique de diffusion des vues consolidées pour l'arbitrage stratégique.",
                ],
                'cabinet' => [
                    'eyebrow' => 'Reporting cabinet',
                    'title' => 'Centre de diffusion transverse',
                    'subtitle' => "Exports consolidés et vue rapide des familles analytiques utiles à l'accompagnement DG.",
                ],
            ],
        ];

        $pageProfiles = $profiles[$page] ?? [];
        $profile = $pageProfiles[$role] ?? ($pageProfiles['default'] ?? [
            'eyebrow' => $user->roleLabel(),
            'title' => 'Lecture consolidée',
            'subtitle' => 'Vue filtrée selon le périmètre courant.',
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

        if ($user->hasRole(User::ROLE_CABINET, User::ROLE_COLLABORATEUR, User::ROLE_CABINET_SUPERVISION, User::ROLE_CHEF_UNITE_CABINET)) {
            return 'cabinet';
        }

        if ($user->hasRole(User::ROLE_ADMIN, User::ROLE_PLANIFICATION, User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL, User::ROLE_CHEF_UNITE_SCIQ)) {
            return 'planification';
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return 'direction';
        }

        if ($user->hasRole(User::ROLE_SERVICE, User::ROLE_CHEF_UNITE, User::ROLE_CHEF_UNITE_UCAS, User::ROLE_UCAS)) {
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

        $unitColumn = SafeSql::identifier($unitColumn, [
            'ptas.direction_id',
            'ptas.service_id',
        ]);

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
                $score = round($this->actionTrackingService->computeActionDelayPriorityScore($action, $today), 2);

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
        return ActionTrackingService::completedActionStatuses();
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
                'alertes_action_actives' => $this->activeActionAlertLogsCount($user),
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

    private function activeActionAlertLogsCount(User $user): int
    {
        return ActionLog::query()
            ->activeAlert()
            ->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
            })
            ->count();
    }

    private function denyUnlessReportingReader(User $user): void
    {
        if ($user->hasPermission('reporting.read')) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    private function denyUnlessAlertReader(User $user): void
    {
        if ($user->hasPermission('alerts.read')) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }
}

