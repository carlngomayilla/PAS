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
use App\Models\Pta;
use App\Models\User;
use App\Support\UiLabel;
use App\Services\Actions\ActionIndicatorService;
use App\Services\Actions\ActionTrackingService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ActionWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadActions($user)) {
            abort(403, 'Acces non autorise.');
        }

        $query = Action::query()
            ->with([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite,kpi_risque',
            ])
            ->withCount([
                'kpis',
                'weeks as semaines_total',
                'weeks as semaines_renseignees' => fn (Builder $q) => $q->where('est_renseignee', true),
            ]);

        $this->scopeAction($query, $user);

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
        $query->when($request->filled('risque_label'), function (Builder $q) use ($request): void {
            $risk = trim((string) $request->string('risque_label'));
            if ($risk === '') {
                return;
            }

            $q->whereRaw('LOWER(risques) like ?', ['%'.mb_strtolower($risk).'%']);
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

        $query->when(
            $request->filled('financement_requis'),
            fn (Builder $q) => $q->where('financement_requis', (bool) $request->boolean('financement_requis'))
        );

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
            'kpi_risque_desc' => $query->orderByDesc(
                ActionKpi::query()->select('kpi_risque')->whereColumn('action_id', 'actions.id')->limit(1)
            )->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        return view('workspace.actions.index', [
            'rows' => $query->paginate(15)->withQueryString(),
            'ptaOptions' => $this->ptaOptions($user),
            'statusOptions' => array_merge(['achevees'], ActionTrackingService::dynamicStatusOptions()),
            'validationOptions' => $this->validationStatusOptions(),
            'sortOptions' => $this->sortOptions(),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pta_id' => $request->filled('pta_id') ? (int) $request->integer('pta_id') : null,
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'pas_objectif_id' => $request->filled('pas_objectif_id') ? (int) $request->integer('pas_objectif_id') : null,
                'annee' => $request->filled('annee') ? (int) $request->integer('annee') : null,
                'mois_demarrage' => $request->filled('mois_demarrage') ? trim((string) $request->string('mois_demarrage')) : '',
                'week_start' => $request->filled('week_start') ? trim((string) $request->string('week_start')) : '',
                'risque_label' => $request->filled('risque_label') ? trim((string) $request->string('risque_label')) : '',
                'statut' => $statusFilter,
                'statut_validation' => (string) $request->string('statut_validation'),
                'financement_requis' => $request->filled('financement_requis')
                    ? (int) $request->integer('financement_requis')
                    : null,
                'without_kpi' => $request->boolean('without_kpi'),
                'sort' => $sort,
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canManageActions($user)) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.actions.form', [
            'mode' => 'create',
            'row' => new Action([
                'type_cible' => 'quantitative',
                'frequence_execution' => ActionTrackingService::FREQUENCE_HEBDOMADAIRE,
                'statut' => 'non_demarre',
                'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
                'financement_requis' => false,
                'seuil_alerte_progression' => 10,
            ]),
            'ptaOptions' => $this->ptaOptions($user),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => ActionTrackingService::dynamicStatusOptions(),
            'indicatorPeriodicityOptions' => $this->indicatorPeriodicityOptions(),
            'indicatorModeOptions' => $this->indicatorInputModeOptions(),
        ]);
    }

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
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        $validated = $this->normalizeActionPayload($validated);
        $pta = Pta::query()->findOrFail((int) $validated['pta_id']);

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

        $action = DB::transaction(function () use ($validated, $indicatorPayload, $request, $trackingService, $indicatorService, $user, $secureStorage): Action {
            $payload = $validated;
            $manualStatus = in_array((string) ($payload['statut'] ?? ''), [
                ActionTrackingService::STATUS_SUSPENDU,
                ActionTrackingService::STATUS_ANNULE,
            ], true)
                ? (string) $payload['statut']
                : 'non_demarre';
            $payload['statut'] = $manualStatus;
            $payload['statut_dynamique'] = match ($manualStatus) {
                ActionTrackingService::STATUS_SUSPENDU => ActionTrackingService::STATUS_SUSPENDU,
                ActionTrackingService::STATUS_ANNULE => ActionTrackingService::STATUS_ANNULE,
                default => ActionTrackingService::STATUS_NON_DEMARRE,
            };
            $payload['progression_reelle'] = 0;
            $payload['progression_theorique'] = 0;
            $payload['frequence_execution'] = $payload['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;
            $payload['date_echeance'] = $payload['date_fin'];

            $action = Action::query()->create($payload);
            $trackingService->initializeActionTracking($action, $user);
            $indicatorService->syncPrimaryIndicator($action, $indicatorPayload);

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

        $this->recordAudit($request, 'action', 'create', $action, null, $action->toArray());
        $notificationService->notifyActionAssigned($action, $user);

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Action creee avec succes et semaines generees automatiquement.');
    }

    public function edit(Request $request, Action $action): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing(
            'pta:id,direction_id,service_id',
            'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner'
        );
        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        return view('workspace.actions.form', [
            'mode' => 'edit',
            'row' => $action,
            'ptaOptions' => $this->ptaOptions($user),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => ActionTrackingService::dynamicStatusOptions(),
            'indicatorPeriodicityOptions' => $this->indicatorPeriodicityOptions(),
            'indicatorModeOptions' => $this->indicatorInputModeOptions(),
        ]);
    }

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
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        $validated = $this->normalizeActionPayload($validated);
        $targetPta = Pta::query()->findOrFail((int) $validated['pta_id']);

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

        $before = $action->toArray();
        $previousResponsableId = (int) ($action->responsable_id ?? 0);

        DB::transaction(function () use ($action, $validated, $indicatorPayload, $request, $trackingService, $indicatorService, $user, $dateChanged, $frequencyChanged, $targetTypeChanged, $secureStorage): void {
            $payload = $validated;
            $payload['date_echeance'] = $payload['date_fin'];
            $action->fill($payload);
            $action->save();
            $indicatorService->syncPrimaryIndicator($action, $indicatorPayload);

            if ($dateChanged || $frequencyChanged || $targetTypeChanged) {
                $trackingService->regenerateWeeks($action);
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
        $this->recordAudit($request, 'action', 'update', $action, $before, $action->toArray());

        $newResponsableId = (int) ($action->responsable_id ?? 0);
        if ($newResponsableId > 0 && $newResponsableId !== $previousResponsableId) {
            $notificationService->notifyActionAssigned($action, $user);
        }

        return redirect()
            ->route('workspace.actions.suivi', $action)
            ->with('success', 'Action mise a jour avec succes.');
    }

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

        if ($action->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Suppression'),
            ]);
        }

        $this->denyUnlessActionManager(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

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
    private function normalizeActionPayload(array $validated): array
    {
        $validated['frequence_execution'] = $validated['frequence_execution']
            ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;
        $validated['seuil_alerte_progression'] = $validated['seuil_alerte_progression'] ?? 10;

        $type = (string) ($validated['type_cible'] ?? '');
        if ($type === 'quantitative') {
            $validated['resultat_attendu'] = null;
            $validated['criteres_validation'] = null;
            $validated['livrable_attendu'] = null;
        } else {
            $validated['unite_cible'] = null;
            $validated['quantite_cible'] = null;
        }

        if (! (bool) ($validated['ressource_autres'] ?? false)) {
            $validated['ressource_autres_details'] = null;
        }

        if (! (bool) ($validated['financement_requis'] ?? false)) {
            $validated['description_financement'] = null;
            $validated['source_financement'] = null;
            $validated['montant_estime'] = null;
        }

        return $validated;
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

        return $this->canWriteService($user, $directionId, $serviceId);
    }

    private function canWrite(User $user): bool
    {
        return $this->canManageActions($user);
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
            ])
            ->orderByDesc('id');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        return $query->get(['id', 'direction_id', 'service_id', 'titre', 'statut']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function responsableOptions(User $user)
    {
        $query = User::query()
            ->where('role', User::ROLE_AGENT)
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

    private function scopeAction(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where('responsable_id', (int) $user->id);
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

        $query->where(function (Builder $scopedQuery) use ($user, $delegatedDirectionIds, $delegatedServiceScopes): void {
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            }

            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            }

            foreach ($delegatedDirectionIds as $directionId) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $directionId));
            }

            foreach ($delegatedServiceScopes as $scope) {
                $scopedQuery->orWhereHas('pta', fn (Builder $q) => $q
                    ->where('direction_id', (int) $scope['direction_id'])
                    ->where('service_id', (int) $scope['service_id']));
            }
        });
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
            'kpi_risque_desc' => 'Risque le plus eleve',
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
