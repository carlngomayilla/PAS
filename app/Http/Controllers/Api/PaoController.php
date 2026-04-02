<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoRequest;
use App\Http\Requests\UpdatePaoRequest;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasObjectif;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'validateur:id,name,email',
            ])
            ->withCount(['ptas']);

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

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
            fn ($q) => $q->where('service_id', (int) $request->integer('service_id'))
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

        return response()->json($result);
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

        $pao = Pao::query()->create([
            ...$validated,
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_objectif_id' => (int) $objectif->id,
        ]);
        $this->recordAudit($request, 'pao', 'create', $pao, null, $pao->toArray());

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pao')),
            'data' => $pao->load([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
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

        if ($pao->ptas()->exists() && (int) $pao->service_id !== (int) $validated['service_id']) {
            return response()->json([
                'message' => 'Le service d un PAO deja decliné en PTA ne peut plus etre modifie.',
            ], 422);
        }

        $before = $pao->toArray();
        $pao->fill([
            ...$validated,
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_objectif_id' => (int) $objectif->id,
        ]);
        $pao->save();

        $this->recordAudit($request, 'pao', 'update', $pao, $before, $pao->toArray());

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pao')),
            'data' => $pao->load([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
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
        $objectif = PasObjectif::query()
            ->with('pasAxe.pas.directions:id')
            ->findOrFail($objectifId);

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $allowed = $objectif->pasAxe?->pas?->directions
                ?->contains(static fn ($direction): bool => (int) $direction->id === (int) $user->direction_id);

            if (! $allowed) {
                abort(403, $this->outOfScopeMessage(UiLabel::object('pas_objectif')));
            }
        }

        return $objectif;
    }
}
