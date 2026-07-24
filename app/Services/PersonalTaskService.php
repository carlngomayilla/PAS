<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\DeletionRequest;
use App\Models\PlanningUnlockRequest;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Alerting\AlertRoutingService;
use App\Services\Analytics\AnalyticsCacheVersionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PersonalTaskService
{
    public function __construct(
        private readonly UserWorkspaceService $workspaceService,
        private readonly AlertRoutingService $alertRoutingService,
        private readonly PersonalScoreService $personalScoreService
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function forUser(User $user, int $limit = 20): array
    {
        $items = $this->collectCached($user);

        return [
            'items' => $items->take($limit)->all(),
            'summary' => $this->summary($user, $items),
        ];
    }

    /**
     * @param  array{q?: string, vue?: string, famille?: string, tri?: string}  $filters
     * @return array{
     *     items: LengthAwarePaginator<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     filtered_summary: array<string, int>,
     *     family_options: array<int, array{code: string, count: int}>
     * }
     */
    public function workspaceForUser(
        User $user,
        array $filters,
        int $perPage = 15,
        int $page = 1
    ): array {
        $items = $this->collectCached($user);
        $filteredItems = $this->filterWorkspaceItems($items, $filters);
        $sortedItems = $this->sortWorkspaceItems($filteredItems, (string) ($filters['tri'] ?? 'priorite'));
        $page = max(1, $page);
        $perPage = in_array($perPage, [15, 25, 50], true) ? $perPage : 15;

        return [
            'items' => new LengthAwarePaginator(
                $sortedItems->forPage($page, $perPage)->values(),
                $sortedItems->count(),
                $perPage,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath()]
            ),
            'summary' => $this->summary($user, $items),
            'filtered_summary' => $this->operationalSummary($filteredItems),
            'family_options' => $items
                ->groupBy(fn (array $task): string => (string) ($task['family'] ?? 'autres'))
                ->map(fn (Collection $tasks, string $code): array => [
                    'code' => $code,
                    'count' => $tasks->count(),
                ])
                ->sortBy('code')
                ->values()
                ->all(),
        ];
    }

    /**
     * Compteur leger des taches ouvertes (badge sidebar) : reutilise la meme
     * collecte cachee que forUser() — un seul calcul des sources de taches
     * par fenetre de cache, partage entre le badge et le dashboard.
     */
    public function openTaskCount(User $user): int
    {
        return $this->collectCached($user)->count();
    }

    /**
     * Collecte des taches mise en cache 60s par utilisateur (comme le centre
     * d'alertes) pour ne pas rejouer les 8 requetes sources a chaque chargement
     * de page (badge sidebar) ni a chaque ouverture du dashboard (forUser).
     *
     * La cle integre la version d'alertes, bumpee sur evenement metier
     * (statut action, kpi_mesure, justificatif, suppression...), afin que la
     * liste reagisse immediatement aux changements sans attendre l'expiration
     * du TTL.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectCached(User $user): Collection
    {
        $version = app(AnalyticsCacheVersionService::class)->alertsVersion();

        return Cache::remember(
            'personal-tasks:collect:'.(int) $user->id.':'.$version,
            now()->addSeconds(60),
            fn (): Collection => $this->collect($user)
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collect(User $user): Collection
    {
        $role = $this->workspaceService->specSidebarRole($user);

        return collect()
            ->merge($this->executionTasks($user))
            ->merge($this->subActionExecutionTasks($user))
            ->merge($this->chefValidationTasks($user, $role))
            ->merge($this->chefSubActionValidationTasks($user, $role))
            ->merge($this->controllerValidationTasks($user, $role))
            ->merge($this->dafFinancingTasks($user, $role))
            ->merge($this->dgFinancingTasks($user, $role))
            ->merge($this->actionAlertTasks($user, $role))
            ->merge($this->planningUnlockTasks($user, $role))
            ->merge($this->deletionRequestTasks($user, $role))
            ->unique('key')
            ->sortBy(fn (array $task): string => sprintf(
                '%d-%d-%012d-%s',
                (bool) ($task['is_overdue'] ?? false) ? 0 : 1,
                (string) ($task['criticality'] ?? 'normale') === 'critique' ? 0 : 1,
                (int) ($task['deadline_timestamp'] ?? PHP_INT_MAX),
                (string) ($task['title'] ?? '')
            ))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function executionTasks(User $user): Collection
    {
        return Action::query()
            ->forResponsable((int) $user->id)
            ->with([
                'pta:id,direction_id,service_id,titre',
                'pta.direction:id,libelle',
                'pta.service:id,libelle',
                'responsable:id,name',
            ])
            ->whereNotIn('statut_dynamique', ActionTrackingService::completedActionStatuses())
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('statut_validation')
                    ->orWhereNotIn('statut_validation', [
                        ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                        ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
                        ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                        ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
                        ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                    ])
                    ->orWhere(function (Builder $financingQuery): void {
                        $financingQuery
                            ->where('financement_requis', true)
                            ->whereIn('financement_statut', [
                                Action::FINANCEMENT_PRE_SIGNALE_DAF,
                                Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                                Action::FINANCEMENT_REJETE_DAF,
                            ]);
                    });
            })
            ->latest()
            ->get()
            ->map(function (Action $action): array {
                $validationStatus = (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE);
                $isCorrection = in_array($validationStatus, [
                    ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
                    ActionTrackingService::VALIDATION_REJETEE_CHEF,
                    ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
                    ActionTrackingService::VALIDATION_CORRECTION_CONTROLE,
                ], true);

                $financingStatus = $action->financementStatus();
                $hasFinancingSubmission = (bool) $action->financement_requis
                    && $financingStatus === Action::FINANCEMENT_PRE_SIGNALE_DAF;
                $hasFinancingCorrection = (bool) $action->financement_requis
                    && in_array($financingStatus, [
                        Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                        Action::FINANCEMENT_REJETE_DAF,
                    ], true);

                if ($hasFinancingCorrection) {
                    $isCorrection = true;
                }

                $deadline = ($isCorrection || $hasFinancingSubmission)
                    ? $this->carbon($action->updated_at)?->copy()->addHours(48)
                    : $this->actionDeadline($action);

                $title = match (true) {
                    $hasFinancingSubmission => 'Dossier financement a soumettre',
                    $hasFinancingCorrection => 'Correction financement DAF',
                    $isCorrection => 'Correction demandee',
                    default => 'Execution action',
                };

                $type = match (true) {
                    $hasFinancingSubmission => 'financement_rmo',
                    $hasFinancingCorrection => 'correction_financement',
                    $isCorrection => 'correction_action',
                    default => 'execution_action',
                };

                return $this->task(
                    key: 'action-execution:'.$action->id,
                    type: $type,
                    title: $title,
                    subject: (string) $action->libelle,
                    context: $this->actionContext($action),
                    responsible: $action->responsable?->name,
                    receivedAt: $isCorrection ? $this->carbon($action->updated_at) : $this->carbon($action->created_at),
                    deadlineAt: $deadline,
                    url: route('workspace.actions.suivi', $action).($hasFinancingSubmission || $hasFinancingCorrection ? '#action-financement' : ''),
                    criticality: $this->criticalityFromDeadline($deadline, $isCorrection || $hasFinancingSubmission ? 'importante' : 'normale'),
                    scoreImpact: match (true) {
                        $hasFinancingSubmission => 'Retard de soumission du dossier imputable au RMO.',
                        $hasFinancingCorrection => 'Retard de correction du dossier imputable au RMO.',
                        $isCorrection => 'Retard de correction imputable au responsable de l action.',
                        default => 'Retard d execution imputable au responsable de l action.',
                    }
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function subActionExecutionTasks(User $user): Collection
    {
        return SousAction::query()
            ->where('agent_id', (int) $user->id)
            ->whereNotIn('statut', ['en_attente_validation_chef', 'validee_chef', 'validee', 'cloturee'])
            ->with([
                'agent:id,name',
                'action:id,pta_id,libelle,responsable_id',
                'action.pta:id,direction_id,service_id,titre',
                'action.pta.service:id,libelle',
            ])
            ->latest()
            ->get()
            ->map(function (SousAction $subAction): array {
                $deadline = $this->carbon($subAction->date_fin);

                return $this->task(
                    key: 'sub-action-execution:'.$subAction->id,
                    type: 'execution_sous_action',
                    title: (string) ($subAction->statut ?? '') === 'rejetee_a_corriger'
                        ? 'Correction sous-action'
                        : 'Execution sous-action',
                    subject: (string) $subAction->libelle,
                    context: $subAction->action?->libelle,
                    responsible: $subAction->agent?->name,
                    receivedAt: $this->carbon($subAction->created_at),
                    deadlineAt: $deadline,
                    url: $subAction->action
                        ? route('workspace.actions.suivi', $subAction->action).'#action-weeks'
                        : route('dashboard'),
                    criticality: $this->criticalityFromDeadline($deadline),
                    scoreImpact: 'Retard d execution imputable au RMO assigne.'
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function chefValidationTasks(User $user, string $role): Collection
    {
        if (! in_array($role, ['chef', 'ucas'], true)) {
            return collect();
        }

        return Action::query()
            ->with(['pta:id,direction_id,service_id,titre', 'pta.service:id,libelle', 'responsable:id,name'])
            ->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)
            ->whereHas('pta', fn (Builder $query) => $this->scopeToUserUnit($query, $user))
            ->latest('soumise_le')
            ->get()
            ->map(function (Action $action): array {
                $received = $this->carbon($action->soumise_le) ?? $this->carbon($action->updated_at);
                $deadline = $received?->copy()->addHours(48);

                return [
                    ...$this->task(
                        key: 'chef-validation:'.$action->id,
                        type: 'validation_chef',
                        title: 'Validation chef',
                        subject: (string) $action->libelle,
                        context: $this->actionContext($action),
                        responsible: $action->responsable?->name,
                        receivedAt: $received,
                        deadlineAt: $deadline,
                        url: route('workspace.actions.suivi', $action).'#action-validation',
                        criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                        scoreImpact: 'Retard de validation impute au valideur, pas a l agent.'
                    ),
                    // A42 — Validation inline depuis Mes taches (cf. actions.review).
                    'can_validate' => true,
                    'action_id' => (int) $action->id,
                    'sous_action_id' => null,
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function chefSubActionValidationTasks(User $user, string $role): Collection
    {
        if (! in_array($role, ['chef', 'ucas'], true)) {
            return collect();
        }

        return SousAction::query()
            ->with([
                'agent:id,name',
                'action:id,pta_id,libelle,responsable_id',
                'action.pta:id,direction_id,service_id,titre',
                'action.pta.service:id,libelle',
            ])
            ->where('statut', 'en_attente_validation_chef')
            ->whereHas('action.pta', fn (Builder $query) => $this->scopeToUserUnit($query, $user))
            ->latest('completed_at')
            ->get()
            ->map(function (SousAction $subAction): array {
                $received = $this->carbon($subAction->completed_at)
                    ?? $this->carbon($subAction->date_realisation)
                    ?? $this->carbon($subAction->updated_at);
                $deadline = $received?->copy()->addHours(48);

                return [
                    ...$this->task(
                        key: 'chef-sub-action-validation:'.$subAction->id,
                        type: 'validation_sous_action_chef',
                        title: 'Validation sous-action',
                        subject: (string) $subAction->libelle,
                        context: $subAction->action?->libelle,
                        responsible: $subAction->agent?->name,
                        receivedAt: $received,
                        deadlineAt: $deadline,
                        url: $subAction->action
                            ? route('workspace.actions.suivi', $subAction->action).'#action-weeks'
                            : route('dashboard'),
                        criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                        scoreImpact: 'Retard de validation impute au chef valideur.'
                    ),
                    // A42 — Validation inline depuis Mes taches (cf. actions.review).
                    'can_validate' => $subAction->action !== null,
                    'action_id' => (int) ($subAction->action?->id ?? 0),
                    'sous_action_id' => (int) $subAction->id,
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function controllerValidationTasks(User $user, string $role): Collection
    {
        if (! in_array($role, ['sciq_planif', 'super_admin'], true)) {
            return collect();
        }

        return Action::query()
            ->with(['pta:id,direction_id,service_id,titre', 'pta.service:id,libelle', 'responsable:id,name'])
            ->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CONTROLE)
            ->latest('evalue_le')
            ->get()
            ->map(function (Action $action): array {
                $received = $this->carbon($action->evalue_le) ?? $this->carbon($action->updated_at);
                $deadline = $received?->copy()->addHours(48);

                return $this->task(
                    key: 'controller-validation:'.$action->id,
                    type: 'validation_controleur',
                    title: 'Controle final',
                    subject: (string) $action->libelle,
                    context: $this->actionContext($action),
                    responsible: $action->responsable?->name,
                    receivedAt: $received,
                    deadlineAt: $deadline,
                    url: route('workspace.actions.suivi', $action).'#action-validation',
                    criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                    scoreImpact: 'Retard impute au controleur, pas au RMO.'
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function dafFinancingTasks(User $user, string $role): Collection
    {
        if ($role !== 'directeur_daf') {
            return collect();
        }

        return Action::query()
            ->with(['pta:id,direction_id,service_id,titre', 'pta.service:id,libelle', 'responsable:id,name'])
            ->where('financement_requis', true)
            ->whereIn('financement_statut', [
                Action::FINANCEMENT_SOUMIS_DAF,
            ])
            ->latest('financement_soumis_le')
            ->get()
            ->map(function (Action $action): array {
                $received = $this->carbon($action->financement_soumis_le)
                    ?? $this->carbon($action->financement_notifie_le)
                    ?? $this->carbon($action->updated_at);
                $deadline = $received?->copy()->addDays(3);

                return $this->task(
                    key: 'daf-financing:'.$action->id,
                    type: 'financement_daf',
                    title: 'Traitement DAF',
                    subject: (string) $action->libelle,
                    context: $this->actionContext($action),
                    responsible: $action->responsable?->name,
                    receivedAt: $received,
                    deadlineAt: $deadline,
                    url: route('workspace.actions.suivi', $action).'#action-financement',
                    criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                    scoreImpact: 'Delai DAF de 3 jours impute a la DAF.'
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function dgFinancingTasks(User $user, string $role): Collection
    {
        if ($role !== 'dg') {
            return collect();
        }

        return Action::query()
            ->with(['pta:id,direction_id,service_id,titre', 'pta.service:id,libelle', 'responsable:id,name'])
            ->where('financement_requis', true)
            ->where('financement_statut', Action::FINANCEMENT_TRANSMIS_DG)
            ->latest('financement_daf_le')
            ->get()
            ->map(function (Action $action): array {
                $received = $this->carbon($action->financement_daf_le)
                    ?? $this->carbon($action->updated_at);
                $deadline = $received?->copy()->addHours(48);

                return $this->task(
                    key: 'dg-financing:'.$action->id,
                    type: 'financement_dg',
                    title: 'Arbitrage DG financement',
                    subject: (string) $action->libelle,
                    context: $this->actionContext($action),
                    responsible: $action->responsable?->name,
                    receivedAt: $received,
                    deadlineAt: $deadline,
                    url: route('workspace.actions.suivi', $action).'#action-financement',
                    criticality: $this->criticalityFromDeadline($deadline, 'critique'),
                    scoreImpact: 'Delai DG de 48h impute au decideur.'
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function actionAlertTasks(User $user, string $role): Collection
    {
        return ActionLog::query()
            ->activeAlert()
            ->with([
                'action:id,pta_id,libelle,responsable_id',
                'action.pta:id,direction_id,service_id,titre',
                'action.pta.service:id,libelle',
                'action.responsable:id,name',
            ])
            ->latest()
            ->get()
            ->filter(fn (ActionLog $log): bool => $log->action instanceof Action
                && $this->alertRoutingService->userCanSeeActionLog($user, $log))
            ->map(function (ActionLog $log) use ($role): array {
                $received = $this->carbon($log->created_at);
                $deadline = $received?->copy()->addHours(48);
                $isCritical = in_array((string) $log->niveau, ['critical', 'urgence'], true);
                $targetRole = strtolower((string) ($log->cible_role ?? ''));
                $title = match (true) {
                    in_array($targetRole, ['responsable', 'agent'], true) => 'Correction anomalie',
                    in_array($targetRole, ['chef_service', 'service'], true) => 'Controle chef',
                    $targetRole === 'dg' || $role === 'dg' => 'Arbitrage critique',
                    in_array($targetRole, ['direction'], true) => 'Traitement direction',
                    default => 'Traitement alerte',
                };

                return $this->task(
                    key: 'action-alert:'.$log->id,
                    type: 'alerte_action',
                    title: $title,
                    subject: (string) ($log->message ?: $log->action?->libelle),
                    context: $log->action?->libelle,
                    responsible: $log->action?->responsable?->name,
                    receivedAt: $received,
                    deadlineAt: $deadline,
                    url: $log->action
                        ? route('workspace.actions.suivi', $log->action).'#action-logs'
                        : route('workspace.notifications.index', ['tab' => 'alertes']),
                    criticality: $isCritical ? 'critique' : $this->criticalityFromDeadline($deadline, 'importante'),
                    scoreImpact: 'Retard de traitement impute au profil de controle.'
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function deletionRequestTasks(User $user, string $role): Collection
    {
        $tasks = collect();

        if ($role === 'super_admin') {
            $tasks = $tasks->merge(
                DeletionRequest::query()
                    ->with('requester:id,name')
                    ->where('status', DeletionRequest::STATUS_PENDING)
                    ->latest()
                    ->get()
                    ->map(function (DeletionRequest $request): array {
                        $received = $this->carbon($request->created_at);
                        $deadline = $received?->copy()->addHours(48);

                        return $this->task(
                            key: 'deletion-request-review:'.$request->id,
                            type: 'decision_suppression',
                            title: 'Decision suppression',
                            subject: (string) ($request->entity_label ?? 'Demande de suppression'),
                            context: (string) $request->module,
                            responsible: $request->requester?->name,
                            receivedAt: $received,
                            deadlineAt: $deadline,
                            url: route('workspace.deletion-requests.index', ['status' => DeletionRequest::STATUS_PENDING]).'#request-'.$request->id,
                            criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                            scoreImpact: 'Retard de decision impute au Super Admin.'
                        );
                    })
            );
        }

        $tasks = $tasks->merge(
            DeletionRequest::query()
                ->where('requested_by', (int) $user->id)
                ->where('status', DeletionRequest::STATUS_COMPLEMENT_REQUESTED)
                ->latest('updated_at')
                ->get()
                ->map(function (DeletionRequest $request): array {
                    $received = $this->carbon($request->updated_at);
                    $deadline = $received?->copy()->addHours(48);

                    return $this->task(
                        key: 'deletion-request-complement:'.$request->id,
                        type: 'complement_suppression',
                        title: 'Complement suppression',
                        subject: (string) ($request->entity_label ?? 'Demande de suppression'),
                        context: (string) ($request->reviewer_note ?? $request->module),
                        responsible: $request->reviewer?->name,
                        receivedAt: $received,
                        deadlineAt: $deadline,
                        url: route('workspace.deletion-requests.index', ['status' => DeletionRequest::STATUS_COMPLEMENT_REQUESTED]).'#request-'.$request->id,
                        criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                        scoreImpact: 'Retard de complement impute au demandeur.'
                    );
                })
        );

        return $tasks;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function planningUnlockTasks(User $user, string $role): Collection
    {
        if (! in_array($role, ['sciq_planif', 'dg'], true)) {
            return collect();
        }

        return PlanningUnlockRequest::query()
            ->with(['requester:id,name'])
            ->whereIn('status', [
                PlanningUnlockRequest::STATUS_SOUMISE,
                PlanningUnlockRequest::STATUS_TRANSMISE,
            ])
            ->latest('updated_at')
            ->get()
            ->map(function (PlanningUnlockRequest $request) use ($role): array {
                $isDg = $role === 'dg';
                $received = $this->carbon($request->transferred_at)
                    ?? $this->carbon($request->planif_avis_at)
                    ?? $this->carbon($request->created_at)
                    ?? $this->carbon($request->updated_at);
                $deadline = $received?->copy()->addHours(48);

                return $this->task(
                    key: 'planning-unlock:'.$role.':'.$request->id,
                    type: $isDg ? 'decision_modification_dg' : 'controle_modification',
                    title: $isDg ? 'Decision modification' : 'Decision SCIQ/Planification',
                    subject: (string) ($request->target_label ?? 'Demande de modification'),
                    context: strtoupper((string) $request->module).' / '.(string) $request->reason,
                    responsible: $request->requester?->name,
                    receivedAt: $received,
                    deadlineAt: $deadline,
                    url: route('workspace.planning-unlocks.index').'#unlock-request-'.$request->id,
                    criticality: $this->criticalityFromDeadline($deadline, 'importante'),
                    scoreImpact: $isDg
                        ? 'Retard de decision impute au DG.'
                        : 'Retard de decision impute au profil SCIQ/Planification.'
                );
            });
    }

    private function scopeToUserUnit(Builder $query, User $user): void
    {
        if ($user->service_id !== null) {
            $query->where('service_id', (int) $user->service_id);

            return;
        }

        if ($user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function actionDeadline(Action $action): ?Carbon
    {
        return $this->carbon($action->date_echeance)
            ?? $this->carbon($action->date_fin);
    }

    private function actionContext(Action $action): string
    {
        $service = (string) ($action->pta?->service?->libelle ?? '');
        $pta = (string) ($action->pta?->titre ?? '');

        return trim($service.($service !== '' && $pta !== '' ? ' / ' : '').$pta) ?: 'Action';
    }

    private function carbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function criticalityFromDeadline(?Carbon $deadline, string $default = 'normale'): string
    {
        if (! $deadline instanceof Carbon) {
            return $default;
        }

        if ($deadline->isPast()) {
            return 'critique';
        }

        if ($deadline->lessThanOrEqualTo(now()->addDay())) {
            return 'importante';
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function task(
        string $key,
        string $type,
        string $title,
        string $subject,
        ?string $context,
        ?string $responsible,
        ?Carbon $receivedAt,
        ?Carbon $deadlineAt,
        string $url,
        string $criticality,
        string $scoreImpact
    ): array {
        $now = now();
        $isOverdue = $deadlineAt instanceof Carbon && $deadlineAt->lessThan($now);
        $remainingMinutes = $deadlineAt instanceof Carbon
            ? (int) $now->diffInMinutes($deadlineAt, false)
            : null;

        return [
            'key' => $key,
            'type' => $type,
            'family' => $this->familyForType($type),
            'title' => $title,
            'subject' => $subject,
            'context' => $context ?: '-',
            'responsible' => $responsible ?: '-',
            'received_at' => $receivedAt,
            'received_timestamp' => $receivedAt?->timestamp,
            'deadline_at' => $deadlineAt,
            'deadline_timestamp' => $deadlineAt?->timestamp,
            'remaining_minutes' => $remainingMinutes,
            'remaining_label' => $this->remainingLabel($remainingMinutes),
            'is_overdue' => $isOverdue,
            'status' => $isOverdue ? 'en_retard' : 'ouverte',
            'criticality' => $criticality,
            'url' => $url,
            'score_impact' => $scoreImpact,
        ];
    }

    private function remainingLabel(?int $remainingMinutes): string
    {
        if ($remainingMinutes === null) {
            return 'Delai non defini';
        }

        $absolute = abs($remainingMinutes);
        $days = intdiv($absolute, 1440);
        $hours = intdiv($absolute % 1440, 60);

        $label = $days > 0
            ? $days.'j '.max(0, $hours).'h'
            : max(0, $hours).'h '.($absolute % 60).'min';

        return $remainingMinutes < 0 ? 'Retard '.$label : 'Reste '.$label;
    }

    private function familyForType(string $type): string
    {
        return match ($type) {
            'execution_action', 'execution_sous_action' => 'execution',
            'correction_action', 'correction_financement', 'complement_suppression' => 'corrections',
            'validation_chef', 'validation_sous_action_chef', 'validation_controleur' => 'validations',
            'financement_rmo', 'financement_daf', 'financement_dg' => 'financements',
            'alerte_action' => 'alertes',
            'decision_suppression', 'decision_modification_dg', 'controle_modification' => 'decisions',
            default => 'autres',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array{q?: string, vue?: string, famille?: string, tri?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterWorkspaceItems(Collection $items, array $filters): Collection
    {
        $search = $this->normalizeSearch((string) ($filters['q'] ?? ''));
        $view = (string) ($filters['vue'] ?? 'toutes');
        $family = (string) ($filters['famille'] ?? '');

        return $items
            ->filter(function (array $task) use ($search, $view, $family): bool {
                if ($family !== '' && (string) ($task['family'] ?? 'autres') !== $family) {
                    return false;
                }

                if ($search !== '') {
                    $haystack = $this->normalizeSearch(implode(' ', [
                        (string) ($task['title'] ?? ''),
                        (string) ($task['subject'] ?? ''),
                        (string) ($task['context'] ?? ''),
                        (string) ($task['responsible'] ?? ''),
                        (string) ($task['type'] ?? ''),
                    ]));

                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }

                $remainingMinutes = $task['remaining_minutes'] ?? null;

                return match ($view) {
                    'retard' => (bool) ($task['is_overdue'] ?? false),
                    'a_24h' => is_int($remainingMinutes) && $remainingMinutes >= 0 && $remainingMinutes <= 1440,
                    'critiques' => (string) ($task['criticality'] ?? '') === 'critique',
                    'sans_echeance' => ! (($task['deadline_at'] ?? null) instanceof Carbon),
                    default => true,
                };
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function sortWorkspaceItems(Collection $items, string $sort): Collection
    {
        return match ($sort) {
            'echeance' => $items->sortBy(fn (array $task): string => sprintf(
                '%d-%012d-%s',
                ($task['deadline_timestamp'] ?? null) === null ? 1 : 0,
                (int) ($task['deadline_timestamp'] ?? PHP_INT_MAX),
                (string) ($task['title'] ?? '')
            ))->values(),
            'reception' => $items->sortByDesc(
                fn (array $task): int => (int) ($task['received_timestamp'] ?? 0)
            )->values(),
            default => $items->values(),
        };
    }

    private function normalizeSearch(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->squish()
            ->toString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function summary(User $user, Collection $items): array
    {
        $scoreSummary = $this->personalScoreService->summarize(
            $user,
            $this->workspaceService->specSidebarRole($user)
        );

        return [
            ...$this->operationalSummary($items),
            'score' => $scoreSummary['score'],
            'quality_label' => $scoreSummary['quality_label'],
            'components' => $scoreSummary['components'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{total: int, open: int, overdue: int, due_soon: int, critical: int, undated: int}
     */
    private function operationalSummary(Collection $items): array
    {
        $total = $items->count();

        return [
            'total' => $total,
            'open' => $total,
            'overdue' => $items->where('is_overdue', true)->count(),
            'due_soon' => $items
                ->filter(function (array $task): bool {
                    $remainingMinutes = $task['remaining_minutes'] ?? null;

                    return is_int($remainingMinutes) && $remainingMinutes >= 0 && $remainingMinutes <= 1440;
                })
                ->count(),
            'critical' => $items->where('criticality', 'critique')->count(),
            'undated' => $items
                ->filter(fn (array $task): bool => ! (($task['deadline_at'] ?? null) instanceof Carbon))
                ->count(),
        ];
    }
}
