<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\EnsuresPtaIsUnlocked;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActionRequest;
use App\Http\Requests\UpdateActionRequest;
use App\Models\Action;
use App\Models\Pta;
use App\Models\User;
use App\Services\Actions\ActionIndicatorService;
use App\Services\Actions\ActionTrackingService;
use App\Services\Security\SecureJustificatifStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite,kpi_risque',
            ])
            ->withCount([
                'kpis',
                'weeks as semaines_total',
                'weeks as semaines_renseignees' => fn ($q) => $q->where('est_renseignee', true),
            ]);

        $this->scopeActionQuery($query, $user);

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

        return response()->json($result);
    }

    public function store(
        StoreActionRequest $request,
        ActionIndicatorService $indicatorService,
        ActionTrackingService $trackingService,
        SecureJustificatifStorage $secureStorage
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        $pta = Pta::query()->findOrFail((int) $validated['pta_id']);

        if ($locked = $this->assertPtaNotLocked($pta)) {
            return $locked;
        }

        $dummyAction = new Action(['pta_id' => $pta->id]);
        $dummyAction->setRelation('pta', $pta);
        $this->authorize('create', $dummyAction);

        $action = DB::transaction(function () use ($validated, $indicatorPayload, $request, $trackingService, $indicatorService, $user, $secureStorage): Action {
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
            $payload['frequence_execution'] = $payload['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;
            $payload['seuil_alerte_progression'] = $payload['seuil_alerte_progression'] ?? 10;
            $payload['date_echeance'] = $payload['date_fin'];

            $action = Action::query()->create($payload);
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

        $this->recordAudit($request, 'action', 'create', $action, null, $action->toArray());

        return response()->json([
            'message' => 'Action creee avec succes.',
            'data' => $action->load([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'weeks:id,action_id,numero_semaine,date_debut,date_fin,est_renseignee',
                'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite,kpi_risque',
            ]),
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
                'responsable:id,name,email,agent_matricule,agent_fonction,agent_telephone',
                'soumisPar:id,name,email',
                'evaluePar:id,name,email',
                'directionValidePar:id,name,email',
                'kpis:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                'weeks' => fn ($q) => $q->orderBy('numero_semaine'),
                'actionKpi',
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

        $this->authorize('update', $action);

        $validated = $request->validated();
        $indicatorPayload = $indicatorService->pullPrimaryIndicatorPayload($validated);
        $targetPta = Pta::query()->findOrFail((int) $validated['pta_id']);

        if ($locked = $this->assertPtaNotLocked($targetPta)) {
            return $locked;
        }

        $dateChanged = (string) $action->date_debut !== (string) ($validated['date_debut'] ?? null)
            || (string) $action->date_fin !== (string) ($validated['date_fin'] ?? null);
        $frequencyChanged = (string) ($action->frequence_execution ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE)
            !== (string) ($validated['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE);
        $targetTypeChanged = (string) $action->type_cible !== (string) ($validated['type_cible'] ?? '');

        if (($dateChanged || $frequencyChanged || $targetTypeChanged) && ! $trackingService->canRegenerateWeeks($action)) {
            return response()->json([
                'message' => 'Impossible de modifier la planification/frequence/type: des periodes sont deja renseignees.',
            ], 422);
        }

        $before = $action->toArray();

        DB::transaction(function () use ($action, $validated, $indicatorPayload, $trackingService, $indicatorService, $dateChanged, $frequencyChanged, $targetTypeChanged, $request, $user, $secureStorage): void {
            $payload = $validated;
            $payload['contexte_action'] = $payload['contexte_action'] ?? Action::CONTEXT_PILOTAGE;
            $payload['origine_action'] = $payload['origine_action'] ?? (
                $payload['contexte_action'] === Action::CONTEXT_OPERATIONNEL
                    ? Action::ORIGIN_INTERNE
                    : Action::ORIGIN_PTA
            );
            $payload['date_echeance'] = $payload['date_fin'];
            $payload['seuil_alerte_progression'] = $payload['seuil_alerte_progression'] ?? 10;
            $payload['frequence_execution'] = $payload['frequence_execution'] ?? ActionTrackingService::FREQUENCE_HEBDOMADAIRE;

            $action->fill($payload);
            $action->save();
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
        $this->recordAudit($request, 'action', 'update', $action, $before, $action->toArray());

        return response()->json([
            'message' => 'Action mise a jour avec succes.',
            'data' => $action->load([
                'pta:id,pao_id,direction_id,service_id,titre,statut',
                'responsable:id,name,email',
                'weeks:id,action_id,numero_semaine,date_debut,date_fin,est_renseignee',
                'primaryKpi:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite,kpi_risque',
            ]),
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

    private function scopeActionQuery(mixed $query, User $user): void
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

        $query->where(function ($scopedQuery) use ($user, $delegatedDirectionIds, $delegatedServiceScopes): void {
            $scopedQuery->orWhere('responsable_id', (int) $user->id);

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
