<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePtaRequest;
use App\Http\Requests\UpdatePtaRequest;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PtaController extends Controller
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

        $this->denyUnlessPlanningReader($user);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));

        $query = Pta::query()
            ->with([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ])
            ->withCount('actions');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        $query->when(
            $request->filled('pao_id'),
            fn ($q) => $q->where('pao_id', (int) $request->integer('pao_id'))
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
            $request->filled('statut'),
            fn ($q) => $q->where('statut', (string) $request->string('statut'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('titre', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StorePtaRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $pao = Pao::query()->findOrFail((int) $validated['pao_id']);
        $serviceId = $pao->service_id !== null ? (int) $pao->service_id : null;
        if ($serviceId === null) {
            return response()->json([
                'message' => 'Le PAO selectionne n est pas encore affecte a un service.',
                'errors' => [
                    'pao_id' => ['Le PAO selectionne n est pas encore affecte a un service.'],
                ],
            ], 422);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pao->direction_id,
            $serviceId
        );

        $pta = Pta::query()->create([
            'pao_id' => (int) $pao->id,
            'direction_id' => (int) $pao->direction_id,
            'service_id' => $serviceId,
            'titre' => (string) $validated['titre'],
            'description' => $validated['description'] ?? null,
            'statut' => (string) ($validated['statut'] ?? 'brouillon'),
            'valide_le' => $validated['valide_le'] ?? null,
            'valide_par' => $validated['valide_par'] ?? null,
        ]);
        $this->recordAudit($request, 'pta', 'create', $pta, null, $pta->toArray());

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pta')),
            'data' => $pta->load([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ]),
        ], 201);
    }

    public function show(Request $request, Pta $pta): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadDirection($user, (int) $pta->direction_id)) {
            abort(403, 'Acces non autorise.');
        }

        if ($user->hasRole(User::ROLE_SERVICE) && (int) $user->service_id !== (int) $pta->service_id) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $pta->load([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
                'actions:id,pta_id,libelle,statut',
            ]),
        ]);
    }

    public function update(UpdatePtaRequest $request, Pta $pta): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedStateMessage('PTA', 'plus etre modifie'),
            ], 409);
        }

        $validated = $request->validated();
        $targetPao = Pao::query()->findOrFail((int) $validated['pao_id']);
        $targetServiceId = $targetPao->service_id !== null ? (int) $targetPao->service_id : null;
        if ($targetServiceId === null) {
            return response()->json([
                'message' => 'Le PAO selectionne n est pas encore affecte a un service.',
                'errors' => [
                    'pao_id' => ['Le PAO selectionne n est pas encore affecte a un service.'],
                ],
            ], 422);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $this->denyUnlessManagePta(
            $user,
            (int) $targetPao->direction_id,
            $targetServiceId
        );

        $before = $pta->toArray();
        $pta->fill([
            'pao_id' => (int) $targetPao->id,
            'direction_id' => (int) $targetPao->direction_id,
            'service_id' => $targetServiceId,
            'titre' => (string) $validated['titre'],
            'description' => $validated['description'] ?? null,
            'statut' => (string) ($validated['statut'] ?? $pta->statut),
            'valide_le' => $validated['valide_le'] ?? null,
            'valide_par' => $validated['valide_par'] ?? null,
        ]);
        $pta->save();

        $this->recordAudit($request, 'pta', 'update', $pta, $before, $pta->toArray());

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pta')),
            'data' => $pta->load([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ]),
        ]);
    }

    public function destroy(Request $request, Pta $pta): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        if ($pta->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedStateMessage('PTA', 'etre supprime'),
            ], 409);
        }

        $before = $pta->toArray();
        $pta->delete();
        $this->recordAudit($request, 'pta', 'delete', $pta, $before, null);

        return response()->json([], 204);
    }
}
