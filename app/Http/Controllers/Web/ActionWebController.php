<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActionRequest;
use App\Http\Requests\UpdateActionRequest;
use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\SousAction;
use App\Models\User;
use App\Support\UiLabel;
use App\Services\ExerciceContext;
use App\Services\ActionCalculationSettings;
use App\Services\ActionManagementSettings;
use App\Services\Actions\ActionIndicatorService;
use App\Services\Actions\ActionTrackingService;
use App\Services\DynamicReferentialSettings;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Contrôleur des Actions (espace web).
 *
 * Gère l'affichage, la création, la modification et la suppression des actions
 * rattachées aux Plans de Travail Annuels (PTA). Les actions sont les tâches
 * concrètes réalisées par les agents.
 *
 * Voir aussi : ActionTrackingWebController pour le suivi et la validation.
 */
class ActionWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    /** Affiche la liste des actions selon les droits de l'utilisateur connecté. */
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadActions($user)) {
            abort(403, 'Acces non autorise.');
        }

        $actionRelations = [
            'pta:id,pao_id,direction_id,service_id,titre,statut',
            'responsable:id,name,email',
            'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite',
        ];
        if (Schema::hasTable('action_responsables')) {
            $actionRelations[] = 'responsables:id,name,email';
        }

        $query = Action::query()
            ->with($actionRelations)
            ->withCount([
                'kpis',
                'justificatifs as justificatifs_total',
                'weeks as semaines_total',
                'weeks as semaines_renseignees' => fn (Builder $q) => $q->where('est_renseignee', true),
            ]);

        $viewMode = $this->applyActionFilters($query, $request, $user);

        $summary = $this->buildActionIndexSummary($query);

        $sort = (string) $request->string('sort');
        match ($sort) {
            'progression_desc' => $query->orderByDesc('progression_reelle')->orderByDesc('id'),
            'kpi_delai_desc' => $query->orderByDesc(
                ActionKpi::query()->select('kpi_delai')->whereColumn('action_id', 'actions.id')->limit(1)
            )->orderByDesc('id'),
            'kpi_performance_desc' => $query->orderByDesc(
                ActionKpi::query()->select('kpi_performance')->whereColumn('action_id', 'actions.id')->limit(1)
            )->orderByDesc('id'),
            'kpi_conformite_desc' => $query->orderByDesc(
                ActionKpi::query()->select('kpi_conformite')->whereColumn('action_id', 'actions.id')->limit(1)
            )->orderByDesc('id'),
            'kpi_global_desc' => $query->orderByDesc(
                ActionKpi::query()->select('kpi_global')->whereColumn('action_id', 'actions.id')->limit(1)
            )->orderByDesc('id'),
            'kpi_qualite_desc' => $query->orderByDesc(
                ActionKpi::query()->select('kpi_qualite')->whereColumn('action_id', 'actions.id')->limit(1)
            )->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $perPage = max(15, min(100, (int) $request->integer('per_page', 15)));

        return view('workspace.actions.index', [
            'rows' => $query->paginate($perPage)->withQueryString(),
            'summary' => $summary,
            'ptaOptions' => $this->ptaOptions($user),
            'statusOptions' => array_merge(['achevees'], ActionTrackingService::dynamicStatusOptions()),
            'validationOptions' => $this->validationStatusOptions(),
            'contextOptions' => Action::contextOptions(),
            'originOptions' => Action::originOptions(),
            'financingStatusOptions' => Action::financingStatusOptions(),
            'sortOptions' => $this->sortOptions(),
            'canWrite' => $this->canWrite($user),
            'showDualActionTabs' => $this->shouldUseDualActionTabs($user),
            'filters' => [
                'vue' => $viewMode,
                'contexte_action' => trim((string) $request->string('contexte_action')),
                'origine_action' => trim((string) $request->string('origine_action')),
                'q' => (string) $request->string('q'),
                'pta_id' => $request->filled('pta_id') ? (int) $request->integer('pta_id') : null,
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'pas_objectif_id' => $request->filled('pas_objectif_id') ? (int) $request->integer('pas_objectif_id') : null,
                'annee' => $request->filled('annee') ? (int) $request->integer('annee') : null,
                'mois_demarrage' => $request->filled('mois_demarrage') ? trim((string) $request->string('mois_demarrage')) : '',
                'week_start' => $request->filled('week_start') ? trim((string) $request->string('week_start')) : '',
                'statut' => trim((string) $request->string('statut')),
                'statut_validation' => $request->filled('statut_validation') ? trim((string) $request->string('statut_validation')) : '',
                'statut_validation_min' => $request->filled('statut_validation_min') ? trim((string) $request->string('statut_validation_min')) : '',
                'financement_requis' => $request->filled('financement_requis') ? (int) $request->boolean('financement_requis') : null,
                'financement_statut' => $request->filled('financement_statut') ? trim((string) $request->string('financement_statut')) : '',
                'without_kpi' => $request->boolean('without_kpi'),
                'per_page' => $perPage,
                'sort' => $sort,
            ],
        ]);
    }

    /** Mise à jour rapide du statut d'une action via un menu déroulant (appel AJAX). */
    public function quickStatus(Request $request, Action $action): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');
        $this->denyUnlessActionManager($user, (int) $action->pta?->direction_id, (int) $action->pta?->service_id);

        $statut = $request->string('statut')->toString();
        if (! in_array($statut, [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)) {
            return response()->json(['error' => 'Statut non autorisé via glisser-déposer.'], 422);
        }

        $currentStatut = (string) ($action->statut_dynamique ?: $action->statut ?: '');
        $terminalStates = [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_CLOTUREE,
        ];
        if (in_array($currentStatut, $terminalStates, true)) {
            return response()->json(['error' => "Action terminée ou clôturée : statut non modifiable."], 422);
        }
        if ($currentStatut === $statut) {
            return response()->json(['error' => "L'action est déjà dans ce statut."], 422);
        }

        $before = $action->only(['statut', 'statut_dynamique']);

        $action->forceFill([
            'statut' => $statut,
            'statut_dynamique' => $statut,
        ])->save();

        $this->recordAudit($request, 'action', 'quick_status', $action, $before, $action->only(['statut', 'statut_dynamique']));

        return response()->json(['statut' => $statut, 'id' => $action->id]);
    }

    /** Vue DAF : liste les actions ayant une demande de financement en cours. */
    public function financingRequests(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->isDafFinanceReviewer($user) && ! $user->hasRole(User::ROLE_DG) && ! $user->hasGlobalReadAccess()) {
            abort(403, 'Acces non autorise.');
        }

        $query = Action::query()
            ->where('financement_requis', true)
            ->with([
                'pta:id,pao_id,direction_id,service_id,titre,statut,created_at',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'objectifOperationnel:id,libelle',
                'responsable:id,name,email',
                'responsables:id,name,email',
                'financementDafPar:id,name,email',
                'justificatifs' => fn ($q) => $q
                    ->whereIn('categorie', ['financement', 'financement_daf'])
                    ->with('ajoutePar:id,name,email')
                    ->latest(),
            ]);
        $exerciseContext = app(ExerciceContext::class);
        if (
            $request->query->has('exercice')
            || $request->query->has('trimestre')
            || ($request->hasSession() && (
                $request->session()->has(ExerciceContext::SESSION_KEY)
                || $request->session()->has(ExerciceContext::QUARTER_SESSION_KEY)
            ))
        ) {
            $exerciseContext->applyToAction($query);
        }

        $statusFilter = trim((string) $request->string('statut_financement'));
        if ($statusFilter !== '') {
            $statuses = [$statusFilter];
            foreach (Action::legacyFinancingStatusMap() as $legacy => $current) {
                if ($current === $statusFilter) {
                    $statuses[] = $legacy;
                }
            }

            $query->whereIn('financement_statut', array_unique($statuses));
        }

        $query->when(
            $request->filled('pta_id'),
            fn (Builder $q) => $q->where('pta_id', (int) $request->integer('pta_id'))
        );
        $query->when(
            $request->filled('direction_id'),
            fn (Builder $q) => $q->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', (int) $request->integer('direction_id')))
        );
        $query->when(
            $request->filled('service_id'),
            fn (Builder $q) => $q->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', (int) $request->integer('service_id')))
        );
        $query->when(
            $request->filled('rmo_id'),
            fn (Builder $q) => $q->where(function (Builder $responsableQuery) use ($request): void {
                $responsableQuery->where('responsable_id', (int) $request->integer('rmo_id'));

                if (Schema::hasTable('action_responsables')) {
                    $responsableQuery->orWhereHas('responsables', fn (Builder $rmoQuery) => $rmoQuery->whereKey((int) $request->integer('rmo_id')));
                }
            })
        );
        $query->when($request->filled('date_debut'), fn (Builder $q) => $q->whereDate('financement_soumis_le', '>=', (string) $request->string('date_debut')));
        $query->when($request->filled('date_fin'), fn (Builder $q) => $q->whereDate('financement_soumis_le', '<=', (string) $request->string('date_fin')));
        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('libelle', 'like', "%{$search}%")
                    ->orWhere('nature_financement', 'like', "%{$search}%")
                    ->orWhere('source_financement', 'like', "%{$search}%")
                    ->orWhere('commentaire_financement', 'like', "%{$search}%");
            });
        });

        $rows = $query
            ->orderByRaw('CASE WHEN financement_soumis_le IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('financement_soumis_le')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('workspace.actions.financements-daf', [
            'rows' => $rows,
            'financingStatusOptions' => Action::financingStatusOptions(),
            'ptaOptions' => Pta::query()->orderByDesc('id')->get(['id', 'titre']),
            'directionOptions' => \App\Models\Direction::query()->orderBy('code')->get(['id', 'code', 'libelle']),
            'serviceOptions' => \App\Models\Service::query()->orderBy('code')->get(['id', 'direction_id', 'code', 'libelle']),
            'rmoOptions' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'canTreatDaf' => $this->isDafFinanceReviewer($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pta_id' => $request->filled('pta_id') ? (int) $request->integer('pta_id') : null,
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'rmo_id' => $request->filled('rmo_id') ? (int) $request->integer('rmo_id') : null,
                'statut_financement' => $statusFilter,
                'date_debut' => (string) $request->string('date_debut'),
                'date_fin' => (string) $request->string('date_fin'),
            ],
        ]);
    }

    /** Affiche le formulaire de création d'une nouvelle action dans un PTA. */
    public function create(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canManageActions($user)) {
            abort(403, 'Acces non autorise.');
        }

        $ptaOptions = $this->ptaOptions($user);
        $objectifOptions = $this->objectifOperationnelOptions($user);
        $prefilledObjectifId = $request->filled('objectif_operationnel_id')
            ? (int) $request->integer('objectif_operationnel_id')
            : null;
        if ($prefilledObjectifId === null && $request->filled('pta_id')) {
            $prefilledObjectifId = Pta::query()
                ->whereKey((int) $request->integer('pta_id'))
                ->value('objectif_operationnel_id');
            $prefilledObjectifId = $prefilledObjectifId !== null ? (int) $prefilledObjectifId : null;
        }
        if ($prefilledObjectifId === null && $request->filled('pao_id')) {
            $prefilledObjectifId = ObjectifOperationnel::query()
                ->where('pao_id', (int) $request->integer('pao_id'))
                ->orderBy('id')
                ->value('id');
            $prefilledObjectifId = $prefilledObjectifId !== null ? (int) $prefilledObjectifId : null;
        }

        return view('workspace.actions.form', [
            'mode' => 'create',
            'row' => new Action([
                'pta_id' => $request->filled('pta_id') ? (int) $request->integer('pta_id') : null,
                'pao_id' => $request->filled('pao_id') ? (int) $request->integer('pao_id') : null,
                'objectif_operationnel_id' => $prefilledObjectifId,
                'type_cible' => 'quantitative',
                'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
                'contexte_action' => (string) $request->string('vue') === 'mes_actions'
                    ? Action::CONTEXT_OPERATIONNEL
                    : Action::CONTEXT_PILOTAGE,
                'origine_action' => (string) $request->string('vue') === 'mes_actions'
                    ? Action::ORIGIN_INTERNE
                    : Action::ORIGIN_PTA,
                'statut' => 'non_demarre',
                'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
                'financement_requis' => false,
                'seuil_alerte_progression' => 10,
            ]),
            'ptaOptions' => $ptaOptions,
            'objectifOptions' => $objectifOptions,
            'actionOptions' => $this->actionOptions($user),
            'paoOptions' => collect(),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => ActionTrackingService::dynamicStatusOptions(),
            'contextOptions' => Action::contextOptions(),
            'originOptions' => Action::originOptions(),
            'financingStatusOptions' => Action::financingStatusOptions(),
            'indicatorPeriodicityOptions' => $this->indicatorPeriodicityOptions(),
            'indicatorModeOptions' => $this->indicatorInputModeOptions(),
            'targetTypeOptions' => $this->actionTargetTypeOptions(),
            'actionUnitSuggestions' => $this->actionUnitSuggestions(),
            'kpiUnitSuggestions' => app(DynamicReferentialSettings::class)->kpiUnitSuggestions(),
            'actionManagementSettings' => app(ActionManagementSettings::class),
        ]);
    }

    /** Valide et enregistre une nouvelle action en base de données. */
    public function store(
        StoreActionRequest $request,
        ActionIndicatorService $indicatorService,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canManageActions($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $existingActionId = isset($validated['existing_action_id']) && is_numeric($validated['existing_action_id'])
            ? (int) $validated['existing_action_id']
            : null;
        $rmoIds = $this->extractRmoIds($validated);
        $subActionsPayload = $this->extractSubActionsPayload($validated);
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        $validated = $this->normalizeActionPayload($validated);
        $objectifOperationnel = ObjectifOperationnel::query()->findOrFail((int) $validated['objectif_operationnel_id']);
        $pta = Pta::query()
            ->whereKey((int) $validated['pta_id'])
            ->where('objectif_operationnel_id', (int) $objectifOperationnel->id)
            ->firstOrFail();
        $validated['pao_id'] = (int) $objectifOperationnel->pao_id;
        $validated['pta_id'] = (int) $pta->id;
        $validated['objectif_operationnel_id'] = (int) $objectifOperationnel->id;

        if ($pta->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pta_id' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Creation'),
            ]);
        }

        $this->denyUnlessActionManager(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $existingAction = $existingActionId !== null
            ? Action::query()
                ->whereKey($existingActionId)
                ->where('pta_id', (int) $pta->id)
                ->where('objectif_operationnel_id', (int) $objectifOperationnel->id)
                ->firstOrFail()
            : null;

        $action = DB::transaction(function () use ($validated, $indicatorPayload, $request, $trackingService, $indicatorService, $user, $secureStorage, $pta, $rmoIds, $subActionsPayload, $existingAction): Action {
            $payload = $validated;
            $payload['frequence_execution'] = $payload['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;
            $payload['date_echeance'] = $payload['date_fin'];
            $payload['exercice_id'] = $pta->exercice_id;

            $action = $existingAction instanceof Action ? $existingAction : Action::query()->make();
            $isNewAction = ! $action->exists;

            if ($isNewAction) {
                $payload['statut'] = 'non_demarre';
                $payload['statut_dynamique'] = ActionTrackingService::STATUS_NON_DEMARRE;
                $payload['progression_reelle'] = 0;
                $payload['progression_theorique'] = 0;
            } else {
                $payload['statut'] = (string) ($action->statut ?: 'non_demarre');
                $payload['statut_dynamique'] = (string) ($action->statut_dynamique ?: ActionTrackingService::STATUS_NON_DEMARRE);
            }

            $action->fill($payload);
            $action->save();
            $this->syncActionRmos($action, $rmoIds);
            $this->syncPlannedSubActions($action, $subActionsPayload, $rmoIds);
            if ($isNewAction) {
                $trackingService->initializeActionTracking($action, $user);
            }
            $indicatorService->syncPrimaryIndicator($action, $indicatorPayload);

            if ((bool) ($action->financement_requis ?? false)) {
                $trackingService->syncFinancingRequest($action, $user);
            }

            if ($request->hasFile('justificatif_financement')) {
                $file = $request->file('justificatif_financement');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));

                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'financement',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif du besoin de financement',
                    $user,
                    $storedFile['est_chiffre']
                );
            }

            return $action;
        });

        $this->recordAudit($request, 'action', $existingAction instanceof Action ? 'update' : 'create', $action, null, $action->toArray());
        $notificationService->notifyActionAssigned($action, $user);
        if ((bool) $action->financement_requis) {
            $notificationService->notifyActionFinancingRequested($action, $user);
            $trackingService->markFinancingNotificationSent($action);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', $existingAction instanceof Action
                ? 'Action liee au PTA mise a jour avec succes.'
                : 'Action creee avec succes et semaines generees automatiquement.');
    }

    /** Affiche le formulaire de modification d'une action existante. */
    public function edit(Request $request, Action $action): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $editRelations = [
            'pta:id,direction_id,service_id',
            'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
            'sousActions:id,action_id,agent_id,libelle,description,resultat_attendu,cible_prevue,unite,commentaire,date_debut,date_fin,quantite_realisee,resultat_obtenu,taux_realisation,statut,est_effectuee,taux_execution',
        ];
        if (Schema::hasTable('action_responsables')) {
            $editRelations[] = 'responsables:id,name,email';
        }
        $action->loadMissing($editRelations);
        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        $ptaOptions = $this->ptaOptions($user);
        $objectifOptions = $this->objectifOperationnelOptions($user, (int) $action->objectif_operationnel_id);

        return view('workspace.actions.form', [
            'mode' => 'edit',
            'row' => $action,
            'ptaOptions' => $ptaOptions,
            'objectifOptions' => $objectifOptions,
            'actionOptions' => $this->actionOptions($user, (int) $action->id),
            'paoOptions' => collect(),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => ActionTrackingService::dynamicStatusOptions(),
            'contextOptions' => Action::contextOptions(),
            'originOptions' => Action::originOptions(),
            'financingStatusOptions' => Action::financingStatusOptions(),
            'indicatorPeriodicityOptions' => $this->indicatorPeriodicityOptions(),
            'indicatorModeOptions' => $this->indicatorInputModeOptions(),
            'targetTypeOptions' => $this->actionTargetTypeOptions(),
            'actionUnitSuggestions' => $this->actionUnitSuggestions(),
            'kpiUnitSuggestions' => app(DynamicReferentialSettings::class)->kpiUnitSuggestions(),
            'actionManagementSettings' => app(ActionManagementSettings::class),
        ]);
    }

    /** Valide et sauvegarde les modifications apportées à une action. */
    public function update(
        UpdateActionRequest $request,
        Action $action,
        ActionIndicatorService $indicatorService,
        ActionTrackingService $trackingService,
        WorkspaceNotificationService $notificationService,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour'),
            ]);
        }

        $validated = $request->validated();
        $rmoIds = $this->extractRmoIds($validated);
        $subActionsPayload = $this->extractSubActionsPayload($validated);
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        $validated = $this->normalizeActionPayload($validated);
        $objectifOperationnel = ObjectifOperationnel::query()->findOrFail((int) $validated['objectif_operationnel_id']);
        $targetPta = Pta::query()
            ->whereKey((int) $validated['pta_id'])
            ->where('objectif_operationnel_id', (int) $objectifOperationnel->id)
            ->firstOrFail();
        $validated['pao_id'] = (int) $objectifOperationnel->pao_id;
        $validated['pta_id'] = (int) $targetPta->id;
        $validated['objectif_operationnel_id'] = (int) $objectifOperationnel->id;

        if ($targetPta->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pta_id' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'cible', 'Mise a jour'),
            ]);
        }

        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
        $this->denyUnlessActionManager(
            $user,
            (int) $targetPta->direction_id,
            (int) $targetPta->service_id
        );

        $dateChanged = (string) $action->date_debut !== (string) ($validated['date_debut'] ?? null)
            || (string) $action->date_fin !== (string) ($validated['date_fin'] ?? null);
        $frequencyChanged = (string) ($action->frequence_execution ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE)
            !== (string) ($validated['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE);
        $targetTypeChanged = (string) $action->type_cible !== (string) ($validated['type_cible'] ?? '');

        if (($dateChanged || $frequencyChanged || $targetTypeChanged) && ! $trackingService->canRegenerateWeeks($action)) {
            return back()
                ->withInput()
                ->withErrors([
                    'date_debut' => 'Impossible de modifier la planification/frequence/type: des periodes sont deja renseignees.',
                ]);
        }

        $validated['exercice_id'] = $targetPta->exercice_id;

        $before = $action->toArray();
        $previousResponsableId = (int) ($action->responsable_id ?? 0);

        DB::transaction(function () use ($action, $validated, $indicatorPayload, $request, $trackingService, $indicatorService, $user, $dateChanged, $frequencyChanged, $targetTypeChanged, $secureStorage, $rmoIds, $subActionsPayload): void {
            $payload = $validated;
            $payload['date_echeance'] = $payload['date_fin'];
            $action->fill($payload);
            $action->save();
            $this->syncActionRmos($action, $rmoIds);
            $this->syncPlannedSubActions($action, $subActionsPayload, $rmoIds);
            $indicatorService->syncPrimaryIndicator($action, $indicatorPayload);

            if ($dateChanged || $frequencyChanged || $targetTypeChanged) {
                $trackingService->regenerateWeeks($action);
            }

            if ((bool) ($action->financement_requis ?? false)) {
                $trackingService->syncFinancingRequest($action, $user);
            }

            if ($request->hasFile('justificatif_financement')) {
                $file = $request->file('justificatif_financement');
                $storedFile = $secureStorage->store($file, 'justificatifs/'.date('Y/m'));

                $trackingService->addActionJustificatif(
                    $action,
                    null,
                    'financement',
                    $storedFile['path'],
                    $storedFile['nom_original'],
                    $storedFile['mime_type'],
                    $storedFile['taille_octets'],
                    'Justificatif du besoin de financement',
                    $user,
                    $storedFile['est_chiffre']
                );
            }

            $trackingService->refreshActionMetrics($action);
        });

        $action->refresh();
        if ($this->shouldResubmitFinancingRequest($before, $action)) {
            $this->resetFinancingWorkflow($action);
            $trackingService->syncFinancingRequest($action, $user);
            $action->refresh();
            $notificationService->notifyActionFinancingRequested($action, $user);
            $trackingService->markFinancingNotificationSent($action);
            $action->refresh();
        } elseif (! (bool) $action->financement_requis && (bool) ($before['financement_requis'] ?? false)) {
            $trackingService->syncFinancingRequest($action, $user);
            $action->refresh();
        }
        $this->recordAudit($request, 'action', 'update', $action, $before, $action->toArray());

        $newResponsableId = (int) ($action->responsable_id ?? 0);
        if ($newResponsableId > 0 && $newResponsableId !== $previousResponsableId) {
            $notificationService->notifyActionAssigned($action, $user);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Action mise a jour avec succes.');
    }

    /** Supprime définitivement une action (avec ses sous-actions, KPI et justificatifs). */
    public function destroy(
        Request $request,
        Action $action,
        SecureJustificatifStorage $secureStorage
    ): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Suppression'),
            ]);
        }

        $before = $action->toArray();

        DB::transaction(function () use ($action, $secureStorage): void {
            $documents = $action->justificatifs()->get(['id', 'chemin_stockage']);
            $paths = $documents->pluck('chemin_stockage')->filter()->all();

            $action->justificatifs()->delete();
            $action->delete();

            foreach ($paths as $path) {
                $secureStorage->deleteByPath((string) $path);
            }
        });

        $this->recordAudit($request, 'action', 'delete', $action, $before, null);

        return redirect()
            ->route('workspace.actions.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('action'), true));
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $before
     */
    private function shouldResubmitFinancingRequest(array $before, Action $action): bool
    {
        if (! (bool) $action->financement_requis) {
            return false;
        }

        $previousRequired = (bool) ($before['financement_requis'] ?? false);
        if (! $previousRequired) {
            return true;
        }

        $previousStatus = (string) ($before['financement_statut'] ?? Action::FINANCEMENT_NON_REQUIS);
        $previousStatus = Action::legacyFinancingStatusMap()[$previousStatus] ?? $previousStatus;
        if (in_array($previousStatus, [Action::FINANCEMENT_NON_REQUIS, Action::FINANCEMENT_REJETE_DAF, Action::FINANCEMENT_REFUSE_DG], true)) {
            return true;
        }

        $sensitiveFields = ['description_financement', 'nature_financement', 'source_financement', 'montant_estime'];
        foreach ($sensitiveFields as $field) {
            if ((string) ($before[$field] ?? '') !== (string) ($action->{$field} ?? '')) {
                return in_array($previousStatus, [Action::FINANCEMENT_VALIDE_DAF, Action::FINANCEMENT_ACCORDE_DG], true);
            }
        }

        return false;
    }

    private function resetFinancingWorkflow(Action $action): void
    {
        $action->forceFill([
            'financement_statut' => Action::FINANCEMENT_A_TRAITER_DAF,
            'financement_soumis_le' => now(),
            'financement_notifie_le' => null,
            'financement_daf_par' => null,
            'financement_daf_le' => null,
            'financement_daf_decision' => null,
            'financement_daf_commentaire' => null,
            'financement_montant_valide' => null,
            'financement_reference' => null,
            'financement_dg_par' => null,
            'financement_dg_le' => null,
            'financement_dg_decision' => null,
            'financement_dg_commentaire' => null,
        ])->save();
    }
    private function normalizeActionPayload(array $validated): array
    {
        $validated['frequence_execution'] = $validated['frequence_execution']
            ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;
        $validated['seuil_alerte_progression'] = $validated['seuil_alerte_progression'] ?? 10;
        $validated['contexte_action'] = (string) ($validated['contexte_action'] ?? Action::CONTEXT_PILOTAGE);
        $validated['origine_action'] = (string) ($validated['origine_action'] ?? (
            $validated['contexte_action'] === Action::CONTEXT_OPERATIONNEL
                ? Action::ORIGIN_INTERNE
                : Action::ORIGIN_PTA
        ));
        $validated['type_cible'] = (string) ($validated['type_cible'] ?? (
            ($validated['quantite_cible'] ?? null) !== null && $validated['quantite_cible'] !== ''
                ? 'quantitative'
                : 'qualitative'
        ));

        $type = (string) ($validated['type_cible'] ?? '');
        if ($type !== 'quantitative') {
            $validated['unite_cible'] = null;
            $validated['quantite_cible'] = null;
        }
        $validated['mode_evaluation'] = $type === 'quantitative'
            ? Action::MODE_QUANTITATIF
            : Action::MODE_SOUS_ACTIONS;

        $validated['seuil_mode'] = in_array(($validated['seuil_mode'] ?? 'unique'), ['unique', 'trimestriel'], true)
            ? (string) $validated['seuil_mode']
            : 'unique';
        $validated['seuil_minimum'] = ($validated['seuil_minimum'] ?? '') === ''
            ? 80
            : (float) $validated['seuil_minimum'];
        foreach (['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4'] as $thresholdKey) {
            $validated[$thresholdKey] = ($validated[$thresholdKey] ?? '') === ''
                ? null
                : (float) $validated[$thresholdKey];
        }

        $validated['criteres_validation'] = isset($validated['criteres_validation'])
            && trim((string) $validated['criteres_validation']) !== ''
                ? trim((string) $validated['criteres_validation'])
                : null;
        $validated['livrable_attendu'] = isset($validated['livrable_attendu'])
            && trim((string) $validated['livrable_attendu']) !== ''
                ? trim((string) $validated['livrable_attendu'])
                : null;

        if (! (bool) ($validated['ressource_autres'] ?? false)) {
            $validated['ressource_autres_details'] = null;
        }

        if (! (bool) ($validated['financement_requis'] ?? false)) {
            $validated['description_financement'] = null;
            $validated['source_financement'] = null;
            $validated['montant_estime'] = null;
        }

        $validated['ressource_main_oeuvre'] = false;
        unset($validated['rmo_ids'], $validated['sous_actions'], $validated['existing_action_id'], $validated['statut'], $validated['statut_dynamique']);

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return list<int>
     */
    private function extractRmoIds(array $validated): array
    {
        $rmoIds = collect($validated['rmo_ids'] ?? [])
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($rmoIds === [] && isset($validated['responsable_id']) && is_numeric($validated['responsable_id'])) {
            $rmoIds = [(int) $validated['responsable_id']];
        }

        return $rmoIds;
    }

    /**
     * @param array<string, mixed> $validated
     * @return list<array<string, mixed>>
     */
    private function extractSubActionsPayload(array $validated): array
    {
        return collect($validated['sous_actions'] ?? [])
            ->filter(fn ($subAction): bool => is_array($subAction))
            ->map(function (array $subAction): array {
                foreach (['id', 'libelle', 'description', 'resultat_attendu', 'date_debut', 'date_fin', 'cible_prevue', 'unite', 'commentaire'] as $key) {
                    $subAction[$key] = $subAction[$key] ?? null;
                }

                return $subAction;
            })
            ->filter(function (array $subAction): bool {
                return collect(['libelle', 'description', 'resultat_attendu', 'cible_prevue', 'commentaire'])
                    ->contains(fn (string $key): bool => trim((string) ($subAction[$key] ?? '')) !== '');
            })
            ->values()
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $subActionsPayload
     * @param list<int> $rmoIds
     */
    private function syncPlannedSubActions(Action $action, array $subActionsPayload, array $rmoIds): void
    {
        if ($subActionsPayload === []) {
            return;
        }

        $defaultAgentId = $rmoIds[0] ?? $action->responsable_id;
        if (! is_numeric($defaultAgentId) || (int) $defaultAgentId <= 0) {
            return;
        }

        foreach ($subActionsPayload as $subActionPayload) {
            $label = trim((string) ($subActionPayload['libelle'] ?? ''));
            if ($label === '') {
                continue;
            }

            $subActionId = isset($subActionPayload['id']) && is_numeric($subActionPayload['id'])
                ? (int) $subActionPayload['id']
                : null;
            $subAction = $subActionId !== null
                ? SousAction::query()
                    ->whereKey($subActionId)
                    ->where('action_id', (int) $action->id)
                    ->first()
                : null;

            $payload = [
                'agent_id' => (int) ($subAction?->agent_id ?: $defaultAgentId),
                'libelle' => $label,
                'description' => ($value = trim((string) ($subActionPayload['description'] ?? ''))) !== '' ? $value : null,
                'resultat_attendu' => ($value = trim((string) ($subActionPayload['resultat_attendu'] ?? ''))) !== '' ? $value : null,
                'cible_prevue' => $this->nullableFloat($subActionPayload['cible_prevue'] ?? null),
                'unite' => ($value = trim((string) ($subActionPayload['unite'] ?? ''))) !== '' ? $value : $action->unite_cible,
                'commentaire' => ($value = trim((string) ($subActionPayload['commentaire'] ?? ''))) !== '' ? $value : null,
                'date_debut' => $subActionPayload['date_debut'] ?? optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString(),
                'date_fin' => $subActionPayload['date_fin'] ?? optional($action->date_fin)->format('Y-m-d') ?? optional($action->date_debut)->format('Y-m-d') ?? now()->toDateString(),
            ];

            if ($subAction instanceof SousAction) {
                $subAction->fill($payload)->save();
                continue;
            }

            $action->sousActions()->create($payload + [
                'quantite_realisee' => 0,
                'resultat_obtenu' => null,
                'taux_realisation' => 0,
                'statut' => 'a_faire',
                'est_effectuee' => false,
                'taux_execution' => 0,
            ]);
        }

        $action->refresh()->recalculateRealization();
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * @param list<int> $rmoIds
     */
    private function syncActionRmos(Action $action, array $rmoIds): void
    {
        if (! Schema::hasTable('action_responsables')) {
            return;
        }

        $primaryId = (int) ($action->responsable_id ?: ($rmoIds[0] ?? 0));
        $ids = collect($rmoIds)
            ->push($primaryId)
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $syncPayload = $ids->mapWithKeys(fn (int $id): array => [
            $id => ['is_primary' => $id === $primaryId],
        ])->all();

        $action->responsables()->sync($syncPayload);
    }

    /**
     * @return list<string>
     */
    private function indicatorPeriodicityOptions(): array
    {
        return ActionIndicatorService::PERIODICITY_OPTIONS;
    }

    /**
     * @return array<string, string>
     */
    private function indicatorInputModeOptions(): array
    {
        return [
            '1' => UiLabel::indicatorInputMode(true),
            '0' => UiLabel::indicatorInputMode(false),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function actionTargetTypeOptions(): array
    {
        return app(DynamicReferentialSettings::class)->actionTargetTypeLabels();
    }

    /**
     * @return list<string>
     */
    private function actionUnitSuggestions(): array
    {
        return app(DynamicReferentialSettings::class)->actionUnitSuggestions();
    }

    private function denyUnlessActionManager(User $user, ?int $directionId, ?int $serviceId): void
    {
        if (! $this->canManageActionScope($user, $directionId, $serviceId)) {
            abort(403, 'Acces non autorise.');
        }
    }

    private function canManageActions(User $user): bool
    {
        return ! $user->isAgent() && (
            $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_DIRECTION)
            || $user->hasRole(User::ROLE_SERVICE)
            || $user->hasDelegatedPermission('planning_write')
        );
    }

    private function canManageActionScope(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($user->isAgent()) {
            return false;
        }

        // Un responsable de direction peut gérer les actions de toute son entité
        if ($user->hasRole(User::ROLE_DIRECTION)
            && $user->direction_id !== null
            && (int) $user->direction_id === (int) $directionId
        ) {
            return true;
        }

        return $this->canWriteService($user, $directionId, $serviceId);
    }

    private function canWrite(User $user): bool
    {
        return $this->canManageActions($user);
    }

    private function applyActionFilters(Builder $query, Request $request, User $user): string
    {
        $this->scopeAction($query, $user);
        app(ExerciceContext::class)->applyToAction(
            $query,
            $request->filled('annee') ? (int) $request->integer('annee') : null
        );

        $showDualActionTabs = $this->shouldUseDualActionTabs($user);
        $viewMode = trim((string) $request->string('vue'));
        if (! in_array($viewMode, ['', 'pilotage', 'mes_actions'], true)) {
            $viewMode = $showDualActionTabs ? 'pilotage' : '';
        }

        if ($showDualActionTabs && $viewMode === '') {
            $viewMode = 'pilotage';
        }

        if ($viewMode === 'pilotage') {
            $query->where('contexte_action', Action::CONTEXT_PILOTAGE);

            if (! $user->isAgent()) {
                $query->where(function (Builder $q) use ($user): void {
                    $q->whereNull('responsable_id')
                        ->orWhere('responsable_id', '!=', (int) $user->id);

                    if (Schema::hasTable('action_responsables')) {
                        $q->whereDoesntHave('responsables', fn (Builder $r) => $r->whereKey((int) $user->id));
                    }
                });
            }
        }

        if ($viewMode === 'mes_actions') {
            $query->where(function (Builder $q) use ($user): void {
                $q->where('responsable_id', (int) $user->id);

                if (Schema::hasTable('action_responsables')) {
                    $q->orWhereHas('responsables', fn (Builder $r) => $r->whereKey((int) $user->id));
                }
            });
        }

        $contextFilter = trim((string) $request->string('contexte_action'));
        if ($contextFilter !== '' && in_array($contextFilter, array_keys(Action::contextOptions()), true)) {
            $query->where('contexte_action', $contextFilter);
        }

        $originFilter = trim((string) $request->string('origine_action'));
        if ($originFilter !== '' && in_array($originFilter, array_keys(Action::originOptions()), true)) {
            $query->where('origine_action', $originFilter);
        }

        $query->when(
            $request->filled('pta_id'),
            fn (Builder $q) => $q->where('pta_id', (int) $request->integer('pta_id'))
        );
        $query->when(
            $request->filled('direction_id'),
            fn (Builder $q) => $q->whereHas(
                'pta',
                fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', (int) $request->integer('direction_id'))
            )
        );
        $query->when(
            $request->filled('service_id'),
            fn (Builder $q) => $q->whereHas(
                'pta',
                fn (Builder $ptaQuery) => $ptaQuery->where('service_id', (int) $request->integer('service_id'))
            )
        );
        $query->when(
            $request->filled('pas_objectif_id'),
            fn (Builder $q) => $q->whereHas(
                'pta.pao',
                fn (Builder $paoQuery) => $paoQuery->where('pas_objectif_id', (int) $request->integer('pas_objectif_id'))
            )
        );
        $query->when(
            $request->filled('annee'),
            fn (Builder $q) => $q->whereHas(
                'pta.pao',
                fn (Builder $paoQuery) => $paoQuery->where('annee', (int) $request->integer('annee'))
            )
        );
        $query->when($request->filled('mois_demarrage'), function (Builder $q) use ($request): void {
            $month = trim((string) $request->string('mois_demarrage'));
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                return;
            }

            [$year, $monthValue] = explode('-', $month, 2);
            $q->whereYear('date_debut', (int) $year)
                ->whereMonth('date_debut', (int) $monthValue);
        });
        $query->when($request->filled('week_start'), function (Builder $q) use ($request): void {
            $weekStart = trim((string) $request->string('week_start'));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) !== 1) {
                return;
            }

            $start = Carbon::parse($weekStart)->startOfDay();
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

            $q->whereHas('weeks', fn (Builder $weeksQuery) => $weeksQuery
                ->whereBetween('date_debut', [$start->toDateString(), $end->toDateString()]));
        });

        $statusFilter = trim((string) $request->string('statut'));
        if ($statusFilter !== '') {
            if ($statusFilter === 'achevees') {
                $query->whereIn('statut_dynamique', $this->completedActionStatuses());
            } else {
                $query->where('statut_dynamique', $statusFilter);
            }
        }

        $query->when(
            $request->filled('statut_validation'),
            fn (Builder $q) => $q->where('statut_validation', (string) $request->string('statut_validation'))
        );
        $query->when($request->filled('statut_validation_min'), function (Builder $q) use ($request): void {
            $threshold = trim((string) $request->string('statut_validation_min'));
            $settings = app(ActionCalculationSettings::class);
            $statuses = $settings->validationStatusesFrom($threshold);

            $q->whereIn('statut_validation', $statuses);
        });

        $query->when(
            $request->filled('financement_requis'),
            fn (Builder $q) => $q->where('financement_requis', (bool) $request->boolean('financement_requis'))
        );

        $financingStatusFilter = trim((string) $request->string('financement_statut'));
        if ($financingStatusFilter !== '' && array_key_exists($financingStatusFilter, Action::financingStatusOptions())) {
            $statuses = [$financingStatusFilter];
            foreach (Action::legacyFinancingStatusMap() as $legacy => $current) {
                if ($current === $financingStatusFilter) {
                    $statuses[] = $legacy;
                }
            }

            $query->whereIn('financement_statut', array_unique($statuses));
        }

        $query->when(
            $request->boolean('without_kpi'),
            fn (Builder $q) => $q->doesntHave('kpis')
        );

        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('resultat_attendu', 'like', "%{$search}%")
                    ->orWhere('description_financement', 'like', "%{$search}%")
                    ->orWhere('source_financement', 'like', "%{$search}%");
            });
        });

        return $viewMode;
    }

    private function canReadActions(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->isAgent()
            || $user->hasDelegatedPermission('action_review')
            || $user->hasDelegatedPermission('planning_write');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Pta>
     */
    private function ptaOptions(User $user)
    {
        $query = Pta::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,code,libelle',
                'objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,libelle,echeance',
                'pao:id,pas_objectif_id,titre,objectif_operationnel',
                'pao.pasObjectif:id,code,libelle',
            ])
            ->orderByDesc('id');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        app(ExerciceContext::class)->applyToPta($query);

        return $query->get(['id', 'pao_id', 'objectif_operationnel_id', 'direction_id', 'service_id', 'titre', 'statut']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Action>
     */
    private function actionOptions(User $user, ?int $forceActionId = null)
    {
        $query = Action::query()
            ->with([
                'pta:id,pao_id,objectif_operationnel_id,direction_id,service_id,titre,statut',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'objectifOperationnel:id,pao_id,pas_id,pas_axe_id,pas_objectif_id,direction_id,service_id,libelle,description,echeance',
            ])
            ->whereNotNull('pta_id')
            ->whereNotNull('objectif_operationnel_id')
            ->orderByDesc('id');

        if (! $user->hasGlobalReadAccess()) {
            $query->whereHas('pta', function (Builder $ptaQuery) use ($user, $forceActionId): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');

                if ($forceActionId !== null) {
                    $ptaQuery->orWhereHas('actions', fn (Builder $actionQuery) => $actionQuery->whereKey($forceActionId));
                }
            });
        } elseif ($forceActionId !== null) {
            $query->orWhereKey($forceActionId);
        }

        return $query->get([
            'id',
            'pta_id',
            'pao_id',
            'objectif_operationnel_id',
            'libelle',
            'description',
            'date_debut',
            'date_fin',
            'statut',
            'quantite_cible',
            'resultat_attendu',
            'montant_estime',
            'responsable_id',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ObjectifOperationnel>
     */
    private function objectifOperationnelOptions(User $user, ?int $forceObjectifId = null)
    {
        $query = ObjectifOperationnel::query()
            ->with([
                'pao:id,pas_id,direction_id,annee,titre,statut',
                'pas:id,titre,periode_debut,periode_fin',
                'pasAxe:id,pas_id,code,libelle',
                'pasObjectif:id,pas_axe_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'ptas:id,pao_id,objectif_operationnel_id,direction_id,service_id,titre,statut',
            ])
            ->orderByDesc('id');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where(function ($scopedQuery) use ($user, $forceObjectifId): void {
                $scopedQuery->where('service_id', (int) $user->service_id);

                if ($forceObjectifId !== null) {
                    $scopedQuery->orWhere('id', $forceObjectifId);
                }
            });
        }

        return $query->get([
            'id',
            'pao_id',
            'pas_id',
            'pas_axe_id',
            'pas_objectif_id',
            'direction_id',
            'service_id',
            'libelle',
            'description',
            'echeance',
            'statut',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Pao>
     */
    private function paoOptions(User $user)
    {
        $query = Pao::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'pasObjectif:id,pas_axe_id,code,libelle',
                'pasObjectif.pasAxe:id,pas_id,code,libelle',
                'pasObjectif.pasAxe.pas:id,titre,periode_debut,periode_fin',
            ])
            ->whereNotNull('service_id')
            ->orderByDesc('annee')
            ->orderByDesc('id');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        app(ExerciceContext::class)->applyToPao($query);

        return $query->get(['id', 'pas_id', 'direction_id', 'service_id', 'pas_objectif_id', 'annee', 'titre', 'objectif_operationnel', 'echeance']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function responsableOptions(User $user)
    {
        $query = User::query()
            ->where('is_active', true)
            ->orderBy('role')
            ->orderBy('name');

        if (! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query->get([
            'id',
            'name',
            'email',
            'direction_id',
            'service_id',
            'agent_matricule',
            'agent_fonction',
            'agent_telephone',
        ]);
    }

    private function isDafFinanceReviewer(User $user): bool
    {
        if (! $user->hasRole(User::ROLE_DIRECTION) || $user->direction_id === null) {
            return false;
        }

        if ($user->relationLoaded('direction')) {
            return (string) ($user->direction?->code ?? '') === 'DAF';
        }

        return $user->direction()->where('code', 'DAF')->exists();
    }

    private function shouldUseDualActionTabs(User $user): bool
    {
        if ($user->isAgent()) {
            return false;
        }

        if (! $user->hasRole(User::ROLE_CABINET, User::ROLE_PLANIFICATION, User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
            return false;
        }

        if ($user->direction_id === null || $user->service_id === null) {
            return false;
        }

        return DB::table('directions')
            ->join('services', 'services.direction_id', '=', 'directions.id')
            ->where('directions.id', (int) $user->direction_id)
            ->where('directions.code', 'DG')
            ->where('services.id', (int) $user->service_id)
            ->whereIn('services.code', ['CAB', 'SCIQ', 'DGA', 'UCAS'])
            ->exists();
    }

    private function scopeAction(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where(function (Builder $agentQuery) use ($user): void {
                $agentQuery->where('responsable_id', (int) $user->id);

                if (Schema::hasTable('action_responsables')) {
                    $agentQuery->orWhereHas('responsables', fn (Builder $responsableQuery) => $responsableQuery->whereKey((int) $user->id));
                }
            });
            return;
        }

        $delegatedDirectionIds = array_values(array_unique(array_merge(
            $user->delegatedDirectionIds('action_review'),
            $user->delegatedDirectionIds('planning_write')
        )));
        $delegatedServiceScopes = array_merge(
            $user->delegatedServiceScopes('action_review'),
            $user->delegatedServiceScopes('planning_write')
        );

        $uniqueServiceScopes = collect($delegatedServiceScopes)
            ->unique(fn (array $s): string => $s['direction_id'].'-'.$s['service_id'])
            ->values()
            ->all();

        $query->where(function (Builder $scopedQuery) use ($user, $delegatedDirectionIds, $uniqueServiceScopes): void {
            $scopedQuery->orWhere('responsable_id', (int) $user->id);

            if (Schema::hasTable('action_responsables')) {
                $scopedQuery->orWhereHas('responsables', fn (Builder $responsableQuery) => $responsableQuery->whereKey((int) $user->id));
            }

            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            }

            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            }

            if (! empty($delegatedDirectionIds)) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q->whereIn('direction_id', $delegatedDirectionIds));
            }

            if (! empty($uniqueServiceScopes)) {
                $scopedQuery->orWhereHas('pta', function (Builder $q) use ($uniqueServiceScopes): void {
                    $q->where(function (Builder $inner) use ($uniqueServiceScopes): void {
                        foreach ($uniqueServiceScopes as $scope) {
                            $inner->orWhere(function (Builder $pair) use ($scope): void {
                                $pair->where('direction_id', (int) $scope['direction_id'])
                                    ->where('service_id', (int) $scope['service_id']);
                            });
                        }
                    });
                });
            }

            if ($this->isDafFinanceReviewer($user) || $user->hasRole(User::ROLE_DG)) {
                $scopedQuery->orWhere('financement_requis', true);
            }
        });
    }

    /**
     * @return array{total:int, avg_progression:float, avg_kpi_global:float, avg_quality:float, funded_count:int, validated_count:int, pending_validation_count:int, pending_justificatif_count:int, status_counts:array<string, int>}
     */
    private function buildActionIndexSummary(Builder $query): array
    {
        $baseQuery = (clone $query)->toBase();
        // Reset des colonnes héritées (actions.* et sous-requêtes withCount) :
        // PostgreSQL rejette les SELECT qui mélangent agrégats et colonnes non-groupées.
        $baseQuery->columns = null;

        $stats = (clone $baseQuery)
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw('AVG(progression_reelle) as avg_progression'),
                DB::raw('SUM(CASE WHEN financement_requis THEN 1 ELSE 0 END) as funded_count'),
            ])
            ->first();

        /** @var array<string, int> $statusCounts */
        $statusCounts = (clone $baseQuery)
            ->select([
                'statut_dynamique as status_label',
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('statut_dynamique')
            ->pluck('total', 'status_label')
            ->map(fn ($value): int => (int) $value)
            ->toArray();

        $pendingValidationStatuses = [
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ];
        $validatedStatuses = [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ];

        $pendingValidationCount = (int) (clone $baseQuery)
            ->whereIn('statut_validation', $pendingValidationStatuses)
            ->count();
        $validatedCount = (int) (clone $baseQuery)
            ->whereIn('statut_validation', $validatedStatuses)
            ->count();
        $pendingJustificatifCount = (int) (clone $baseQuery)
            ->where('statut_dynamique', ActionTrackingService::STATUS_NON_DEMARRE)
            ->count();

        // Execution performance is aggregated from the dedicated action KPI table.
        $actionIdsQuery = (clone $query)->toBase()->select('actions.id');
        $kpiBase = DB::query()
            ->fromSub($actionIdsQuery, 'filtered_actions')
            ->join('action_kpis', 'action_kpis.action_id', '=', 'filtered_actions.id');

        $avgKpiGlobal = (clone $kpiBase)->avg('action_kpis.kpi_performance');
        $avgQuality = (clone $kpiBase)->avg('action_kpis.kpi_qualite');

        return [
            'total' => (int) ($stats?->total ?? 0),
            'avg_progression' => round((float) ($stats?->avg_progression ?? 0), 2),
            'avg_kpi_global' => round((float) ($avgKpiGlobal ?? 0), 2),
            'avg_quality' => round((float) ($avgQuality ?? 0), 2),
            'funded_count' => (int) ($stats?->funded_count ?? 0),
            'validated_count' => $validatedCount,
            'pending_validation_count' => $pendingValidationCount,
            'pending_justificatif_count' => $pendingJustificatifCount,
            'status_counts' => $statusCounts,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function validationStatusOptions(): array
    {
        return [
            ActionTrackingService::VALIDATION_NON_SOUMISE,
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortOptions(): array
    {
        return [
            '' => 'Plus recentes',
            'progression_desc' => 'Progression la plus forte',
            'kpi_delai_desc' => UiLabel::metric('delai').' le plus eleve',
            'kpi_performance_desc' => UiLabel::metric('performance').' le plus eleve',
            'kpi_conformite_desc' => UiLabel::metric('conformite').' le plus eleve',
            'kpi_global_desc' => UiLabel::metric('global').' le plus eleve',
            'kpi_qualite_desc' => 'Qualite la plus forte',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function completedActionStatuses(): array
    {
        return [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
        ];
    }
}
