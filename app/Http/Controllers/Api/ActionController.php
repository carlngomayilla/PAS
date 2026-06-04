<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\EnsuresPtaIsUnlocked;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Requests\StoreActionRequest;
use App\Http\Requests\UpdateActionRequest;
use App\Models\Action;
use App\Models\Pta;
use App\Models\User;
use App\Services\Actions\ActionIndicatorService;
use App\Services\Actions\ActionTrackingService;
use App\Services\ExerciceContext;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\PlanningModificationLockService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ActionController extends Controller
{
    use AuthorizesPlanningScope;
    use EnsuresPtaIsUnlocked;
    use RecordsAuditTrail;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('viewAny', Action::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));

        $query = Action::query()
            ->with([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,progression_reelle,progression_theorique,statut_calcule',
            ])
            ->withCount([
                'kpis',
                'weeks as semaines_total',
                'weeks as semaines_renseignees' => fn ($q) => $q->where('est_renseignee', true),
            ]);

        $this->scopeActionQuery($query, $user);
        app(ExerciceContext::class)->applyToAction($query);

        $viewMode = trim((string) $request->string('vue'));
        if ($viewMode === 'pilotage') {
            $query->where('contexte_action', Action::CONTEXT_PILOTAGE);

            if (! $user->isAgent()) {
                $query->where(function ($q) use ($user): void {
                    $q->whereNull('responsable_id')
                        ->orWhere('responsable_id', '!=', (int) $user->id);
                });
            }
        } elseif ($viewMode === 'mes_actions') {
            $query->where('responsable_id', (int) $user->id);
        }

        $query->when(
            $request->filled('contexte_action'),
            fn ($q) => $q->where('contexte_action', (string) $request->string('contexte_action'))
        );

        $query->when(
            $request->filled('origine_action'),
            fn ($q) => $q->where('origine_action', (string) $request->string('origine_action'))
        );

        $query->when(
            $request->filled('pta_id'),
            fn ($q) => $q->where('pta_id', (int) $request->integer('pta_id'))
        );

        $query->when(
            $request->filled('responsable_id'),
            fn ($q) => $q->where('responsable_id', (int) $request->integer('responsable_id'))
        );

        $query->when(
            $request->filled('statut'),
            fn ($q) => $q->where('statut_dynamique', (string) $request->string('statut'))
        );

        $query->when(
            $request->filled('financement_requis'),
            fn ($q) => $q->where('financement_requis', $request->boolean('financement_requis'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('resultat_attendu', 'like', "%{$search}%")
                    ->orWhere('description_financement', 'like', "%{$search}%")
                    ->orWhere('source_financement', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return ActionResource::collection($result)->response();
    }

    public function store(
        StoreActionRequest $request,
        ActionIndicatorService $indicatorService,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage,
        WorkspaceNotificationService $notificationService
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $rmoIds = $this->extractRmoIds($validated);
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        unset($validated['rmo_ids']);
        $pta = Pta::query()->findOrFail((int) $validated['pta_id']);

        if ($locked = $this->assertPtaNotLocked($pta)) {
            return $locked;
        }

        $dummyAction = new Action(['pta_id' => $pta->id]);
        $dummyAction->setRelation('pta', $pta);
        $this->authorize('create', $dummyAction);

        $action = DB::transaction(function () use ($validated, $indicatorPayload, $request, $trackingService, $indicatorService, $user, $secureStorage, $pta, $rmoIds): Action {
            $payload = $validated;
            $payload['contexte_action'] = $payload['contexte_action'] ?? Action::CONTEXT_PILOTAGE;
            $payload['origine_action'] = $payload['origine_action'] ?? (
                $payload['contexte_action'] === Action::CONTEXT_OPERATIONNEL
                    ? Action::ORIGIN_INTERNE
                    : Action::ORIGIN_PTA
            );
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
            unset($payload['frequence_execution']);
            $payload['seuil_alerte_progression'] = $payload['seuil_alerte_progression'] ?? 10;
            $payload['date_echeance'] = $payload['date_fin'];
            $payload['exercice_id'] = $pta->exercice_id;
            $payload['unite_dg_id'] = $this->primaryRmoUniteId($rmoIds);

            // forceFill : statut / statut_dynamique / progression_* ne sont plus
            // mass-assignables (cf. A02). Le payload provient ici de $validated +
            // valeurs internes posees par le controleur, jamais d input direct.
            $action = new Action();
            $action->forceFill($payload)->save();
            $this->syncActionRmos($action, $rmoIds);
            $trackingService->initializeActionTracking($action, $user);
            $indicatorService->syncPrimaryIndicator($action, $indicatorPayload);

            if ($request->hasFile('justificatif_financement')) {
                $file = $request->file('justificatif_financement');
                $storedFile = $secureStorage->store($file, 'justificatifs/' . date('Y/m'));

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
        app(PlanningModificationLockService::class)->lockAfterSave($action, $user);

        $this->recordAudit($request, 'action', 'create', $action, null, $action->toArray());
        $notificationService->notifyActionAssigned($action, $user);

        return response()->json([
            'message' => 'Action creee avec succes.',
            'data' => $action->load($this->actionResponseRelations()),
        ], 201);
    }

    public function show(Request $request, Action $action, ActionTrackingService $trackingService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id');
        $this->authorize('view', $action);

        $trackingService->refreshActionMetrics($action);

        return response()->json([
            'data' => $action->load([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'pta.pao:id,pas_id,annee,titre,statut',
                'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
                'pao:id,pas_id,annee,titre,statut,objectif_operationnel,echeance',
                'pao.pas:id,titre,periode_debut,periode_fin,statut',
                'responsable:id,name,email,agent_matricule,agent_fonction,agent_telephone',
                'soumisPar:id,name,email',
                'evaluePar:id,name,email',
                // directionValidePar supprime avec la migration de purge direction.
                'kpis:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                // 'weeks' supprime : le suivi hebdomadaire n'existe plus.
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,progression_reelle,progression_theorique,statut_calcule',
                'actionLogs' => fn ($q) => $q->latest()->limit(50),
                'justificatifs' => fn ($q) => $q->latest(),
            ]),
        ]);
    }

    public function update(
        UpdateActionRequest $request,
        Action $action,
        ActionIndicatorService $indicatorService,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }
        if ($message = app(PlanningModificationLockService::class)->ensureUnlocked($action, $request->user())) {
            return response()->json(['message' => $message], 409);
        }

        $this->authorize('update', $action);

        $validated = $request->validated();
        $rmoIds = $this->extractRmoIds($validated);
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        unset($validated['rmo_ids']);
        $targetPta = Pta::query()->findOrFail((int) $validated['pta_id']);

        if ($locked = $this->assertPtaNotLocked($targetPta)) {
            return $locked;
        }

        $dateChanged = (string) $action->date_debut !== (string) ($validated['date_debut'] ?? null)
            || (string) $action->date_fin !== (string) ($validated['date_fin'] ?? null);
        $frequencyChanged = false;
        $targetTypeChanged = (string) $action->type_cible !== (string) ($validated['type_cible'] ?? '');

        if (($dateChanged || $frequencyChanged || $targetTypeChanged) && ! $trackingService->canRegenerateWeeks($action)) {
            return response()->json([
                'message' => 'Impossible de modifier la planification/frequence/type: des periodes sont deja renseignees.',
            ], 422);
        }

        $validated['exercice_id'] = $targetPta->exercice_id;

        $before = $action->toArray();

        DB::transaction(function () use ($action, $validated, $indicatorPayload, $trackingService, $indicatorService, $dateChanged, $frequencyChanged, $targetTypeChanged, $request, $user, $secureStorage, $rmoIds): void {
            $payload = $validated;
            $payload['contexte_action'] = $payload['contexte_action'] ?? Action::CONTEXT_PILOTAGE;
            $payload['origine_action'] = $payload['origine_action'] ?? (
                $payload['contexte_action'] === Action::CONTEXT_OPERATIONNEL
                    ? Action::ORIGIN_INTERNE
                    : Action::ORIGIN_PTA
            );
            $payload['date_echeance'] = $payload['date_fin'];
            $payload['seuil_alerte_progression'] = $payload['seuil_alerte_progression'] ?? 10;
            unset($payload['frequence_execution']);
            $payload['unite_dg_id'] = $this->primaryRmoUniteId($rmoIds) ?? $action->unite_dg_id;

            // L update API n autorise pas l ecriture des champs workflow (cf. A02).
            // Le payload est construit a partir de $validated qui ne contient pas
            // statut/valide_*/financement_*; fill suffit (les cles eventuelles
            // injectees seraient silencieusement ignorees).
            $action->fill($payload);
            $action->save();
            $this->syncActionRmos($action, $rmoIds);
            $indicatorService->syncPrimaryIndicator($action, $indicatorPayload);

            if ($dateChanged || $frequencyChanged || $targetTypeChanged) {
                $trackingService->regenerateWeeks($action);
            }

            if ($request->hasFile('justificatif_financement')) {
                $file = $request->file('justificatif_financement');
                $storedFile = $secureStorage->store($file, 'justificatifs/' . date('Y/m'));

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
        app(PlanningModificationLockService::class)->lockAfterSave($action, $user);
        $action->refresh();
        $this->recordAudit($request, 'action', 'update', $action, $before, $action->toArray());

        return response()->json([
            'message' => 'Action mise a jour avec succes.',
            'data' => $action->load($this->actionResponseRelations()),
        ]);
    }

    public function destroy(
        Request $request,
        Action $action,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $action->loadMissing('pta:id,direction_id,service_id,statut');

        if ($locked = $this->assertPtaNotLocked($action->pta)) {
            return $locked;
        }
        if ($message = app(PlanningModificationLockService::class)->ensureUnlocked($action, $request->user())) {
            return response()->json(['message' => $message], 409);
        }

        $this->authorize('delete', $action);

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

        return response()->json([], 204);
    }

    /**
     * @return list<string>
     */
    private function actionResponseRelations(): array
    {
        $relations = [
            'pta:id,pao_id,direction_id,service_id,titre,statut',
            'pao:id,pas_id,annee,titre,statut,objectif_operationnel,echeance',
            'responsable:id,name,email',
            'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
            'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,progression_reelle,progression_theorique,statut_calcule',
        ];

        if (Schema::hasTable('action_responsables')) {
            $relations[] = 'responsables:id,name,email';
        }

        return $relations;
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
     * @param list<int> $rmoIds
     */
    private function primaryRmoUniteId(array $rmoIds): ?int
    {
        if (! isset($rmoIds[0]) || (int) $rmoIds[0] <= 0) {
            return null;
        }

        $unitId = User::query()->whereKey((int) $rmoIds[0])->value('unite_dg_id');

        return $unitId !== null ? (int) $unitId : null;
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

        $action->responsables()->sync(
            $ids->mapWithKeys(fn (int $id): array => [
                $id => ['is_primary' => $id === $primaryId],
            ])->all()
        );
    }

    private function scopeActionQuery(mixed $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where(function ($agentQuery) use ($user): void {
                $agentQuery->where('responsable_id', (int) $user->id);

                if (Schema::hasTable('action_responsables')) {
                    $agentQuery->orWhereHas('responsables', fn ($responsableQuery) => $responsableQuery->whereKey((int) $user->id));
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

        $query->where(function ($scopedQuery) use ($user, $delegatedDirectionIds, $delegatedServiceScopes): void {
            $scopedQuery->orWhere('responsable_id', (int) $user->id);

            if (Schema::hasTable('action_responsables')) {
                $scopedQuery->orWhereHas('responsables', fn ($responsableQuery) => $responsableQuery->whereKey((int) $user->id));
            }

            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery->where('direction_id', (int) $user->direction_id));
            }

            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery->where('service_id', (int) $user->service_id));
            }

            foreach ($delegatedDirectionIds as $directionId) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery->where('direction_id', (int) $directionId));
            }

            foreach ($delegatedServiceScopes as $scope) {
                $scopedQuery->orWhereHas('pta', fn ($subQuery) => $subQuery
                    ->where('direction_id', (int) $scope['direction_id'])
                    ->where('service_id', (int) $scope['service_id']));
            }
        });
    }
}
