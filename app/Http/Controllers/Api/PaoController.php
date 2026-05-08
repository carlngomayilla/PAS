<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaoResource;
use App\Http\Requests\StorePaoRequest;
use App\Http\Requests\UpdatePaoRequest;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasObjectif;
use App\Models\User;
use App\Support\UiLabel;
use App\Services\ExerciceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaoController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('viewAny', Pao::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));

        $query = Pao::query()
            ->with([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
                'validateur:id,name,email',
            ])
            ->withCount(['ptas', 'objectifsOperationnels']);

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        if (! $request->filled('annee')) {
            app(ExerciceContext::class)->applyToPao($query);
        }

        $query->when(
            $request->filled('pas_id'),
            fn ($q) => $q->whereHas(
                'pasObjectif.pasAxe',
                fn ($subQuery) => $subQuery->where('pas_id', (int) $request->integer('pas_id'))
            )
        );
        $query->when(
            $request->filled('pas_objectif_id'),
            fn ($q) => $q->where('pas_objectif_id', (int) $request->integer('pas_objectif_id'))
        );

        $query->when(
            $request->filled('direction_id'),
            fn ($q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('service_id'),
            fn ($q) => $q->where(function ($serviceQuery) use ($request): void {
                $serviceId = (int) $request->integer('service_id');
                $serviceQuery->where('service_id', $serviceId)
                    ->orWhereHas('objectifsOperationnels', fn ($objectiveQuery) => $objectiveQuery->where('service_id', $serviceId));
            })
        );

        $query->when(
            $request->filled('annee'),
            fn ($q) => $q->where('annee', (int) $request->integer('annee'))
        );

        $query->when(
            $request->filled('statut'),
            fn ($q) => $q->where('statut', (string) $request->string('statut'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('titre', 'like', "%{$search}%")
                    ->orWhere('objectif_operationnel', 'like', "%{$search}%")
                    ->orWhere('resultats_attendus', 'like', "%{$search}%")
                    ->orWhere('indicateurs_associes', 'like', "%{$search}%")
                    ->orWhereHas('objectifsOperationnels', fn ($objectiveQuery) => $objectiveQuery
                        ->where('libelle', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('indicateurs', 'like', "%{$search}%"))
                    ->orWhereHas('pasObjectif', fn ($objectifQuery) => $objectifQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('libelle', 'like', "%{$search}%"))
                    ->orWhereHas('pasObjectif.pasAxe', fn ($axeQuery) => $axeQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('libelle', 'like', "%{$search}%"));
            });
        });

        $result = $query
            ->orderByDesc('annee')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return PaoResource::collection($result)->response();
    }

    public function store(StorePaoRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $this->authorize('create', [Pao::class, (int) $validated['direction_id']]);
        $objectif = $this->resolveAccessibleObjectif($user, (int) $validated['pas_objectif_id']);

        $operationalObjectives = $this->validatedOperationalObjectives($validated);
        $pao = DB::transaction(function () use ($validated, $objectif, $request, $operationalObjectives): Pao {
            $pao = Pao::query()->create($this->paoPayload($objectif, $validated, $operationalObjectives[0]));
            $this->recordAudit($request, 'pao', 'create', $pao, null, $pao->toArray());

            foreach ($operationalObjectives as $operationalObjective) {
                $objective = $pao->objectifsOperationnels()->create(
                    $this->operationalObjectivePayload($objectif, $validated, $operationalObjective)
                );
                $this->recordAudit($request, 'objectif_operationnel', 'create', $objective, null, $objective->toArray());
            }

            return $pao;
        });

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pao')),
            'created_count' => count($operationalObjectives),
            'data' => $pao->load([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
                'validateur:id,name,email',
            ]),
        ], 201);
    }

    public function show(Request $request, Pao $pao): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('view', $pao);

        return response()->json([
            'data' => $pao->load([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
                'axes:id,pao_id,code,libelle,ordre',
                'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
            ]),
        ]);
    }

    public function update(UpdatePaoRequest $request, Pao $pao): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedStateMessage('PAO', 'plus etre modifie'),
            ], 409);
        }

        $validated = $request->validated();

        $this->authorize('update', $pao);
        $this->authorize('create', [Pao::class, (int) $validated['direction_id']]);
        $objectif = $this->resolveAccessibleObjectif($user, (int) $validated['pas_objectif_id']);

        $operationalObjectives = $this->validatedOperationalObjectives($validated);
        $before = $pao->toArray();
        DB::transaction(function () use ($pao, $before, $validated, $objectif, $request, $operationalObjectives): void {
            $pao->fill($this->paoPayload($objectif, $validated, $operationalObjectives[0]));
            $pao->save();
            $this->recordAudit($request, 'pao', 'update', $pao, $before, $pao->toArray());

            $keptObjectiveIds = [];
            foreach ($operationalObjectives as $operationalObjective) {
                $objectiveId = (int) ($operationalObjective['id'] ?? 0);
                $payload = $this->operationalObjectivePayload($objectif, $validated, $operationalObjective);

                if ($objectiveId > 0) {
                    $objective = $pao->objectifsOperationnels()->whereKey($objectiveId)->first();
                    if ($objective instanceof ObjectifOperationnel) {
                        $beforeObjective = $objective->toArray();
                        $objective->update($payload);
                        $this->recordAudit($request, 'objectif_operationnel', 'update', $objective, $beforeObjective, $objective->toArray());
                        $keptObjectiveIds[] = (int) $objective->id;
                        continue;
                    }
                }

                $objective = $pao->objectifsOperationnels()->create($payload);
                $this->recordAudit($request, 'objectif_operationnel', 'create', $objective, null, $objective->toArray());
                $keptObjectiveIds[] = (int) $objective->id;
            }

            $pao->objectifsOperationnels()
                ->whereNotIn('id', $keptObjectiveIds)
                ->whereDoesntHave('ptas')
                ->whereDoesntHave('actions')
                ->delete();
        });

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pao')),
            'data' => $pao->load([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
                'validateur:id,name,email',
            ]),
        ]);
    }

    public function destroy(Request $request, Pao $pao): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->authorize('delete', $pao);

        if ($pao->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedStateMessage('PAO', 'etre supprime'),
            ], 409);
        }

        $before = $pao->toArray();
        $pao->delete();
        $this->recordAudit($request, 'pao', 'delete', $pao, $before, null);

        return response()->json([], 204);
    }

    private function resolveAccessibleObjectif(User $user, int $objectifId): PasObjectif
    {
        return PasObjectif::query()
            ->with('pasAxe.pas:id,titre,periode_debut,periode_fin')
            ->findOrFail($objectifId);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, array{id: int|null, libelle: string, service_id: int, echeance: string|null, description: string|null, indicateurs: string|null}>
     */
    private function validatedOperationalObjectives(array $validated): array
    {
        return collect($validated['objectifs_operationnels'] ?? [])
            ->filter(fn ($objective): bool => is_array($objective))
            ->map(fn (array $objective): array => [
                'id' => isset($objective['id']) && is_numeric($objective['id']) ? (int) $objective['id'] : null,
                'libelle' => trim((string) ($objective['libelle'] ?? '')),
                'service_id' => (int) ($objective['service_id'] ?? 0),
                'echeance' => isset($objective['echeance']) && $objective['echeance'] !== ''
                    ? (string) $objective['echeance']
                    : null,
                'description' => isset($objective['description']) && trim((string) $objective['description']) !== ''
                    ? trim((string) $objective['description'])
                    : null,
                'indicateurs' => isset($objective['indicateurs']) && trim((string) $objective['indicateurs']) !== ''
                    ? trim((string) $objective['indicateurs'])
                    : null,
            ])
            ->filter(fn (array $objective): bool => $objective['libelle'] !== '' && $objective['service_id'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $validated
     * @param array{id: int|null, libelle: string, service_id: int, echeance: string|null, description: string|null, indicateurs: string|null} $operationalObjective
     * @return array<string, mixed>
     */
    private function paoPayload(PasObjectif $objectif, array $validated, array $operationalObjective): array
    {
        return [
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => (int) $operationalObjective['service_id'],
            'annee' => (int) $validated['annee'],
            'titre' => trim((string) ($validated['titre'] ?? '')) !== ''
                ? (string) $validated['titre']
                : 'PAO '.(int) $validated['annee'].' - '.$operationalObjective['libelle'],
            'echeance' => $operationalObjective['echeance'],
            'objectif_operationnel' => $operationalObjective['libelle'],
            'resultats_attendus' => $operationalObjective['description'],
            'indicateurs_associes' => $operationalObjective['indicateurs'],
            'statut' => (string) ($validated['statut'] ?? 'brouillon'),
            'exercice_id' => app(ExerciceContext::class)->idForYear((int) $validated['annee']),
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @param array{id: int|null, libelle: string, service_id: int, echeance: string|null, description: string|null, indicateurs: string|null} $operationalObjective
     * @return array<string, mixed>
     */
    private function operationalObjectivePayload(PasObjectif $objectif, array $validated, array $operationalObjective): array
    {
        return [
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_axe_id' => (int) $objectif->pas_axe_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => (int) $operationalObjective['service_id'],
            'libelle' => (string) $operationalObjective['libelle'],
            'description' => $operationalObjective['description'],
            'echeance' => $operationalObjective['echeance'],
            'indicateurs' => $operationalObjective['indicateurs'],
            'statut' => (string) ($validated['statut'] ?? 'brouillon'),
        ];
    }
}
