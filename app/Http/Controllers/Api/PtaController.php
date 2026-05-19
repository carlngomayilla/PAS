<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Resources\PtaResource;
use App\Http\Requests\StorePtaRequest;
use App\Http\Requests\UpdatePtaRequest;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\User;
use App\Support\UiLabel;
use App\Services\ExerciceContext;
use App\Services\Notifications\WorkspaceNotificationService;
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
                'objectifOperationnel:id,pao_id,libelle,echeance',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ])
            ->withCount('actions');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        app(ExerciceContext::class)->applyToPta($query);

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

        return PtaResource::collection($result)->response();
    }

    public function store(StorePtaRequest $request, WorkspaceNotificationService $notificationService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $objectifOperationnel = ObjectifOperationnel::query()
            ->with(['pao:id,pas_id,direction_id,service_id,annee,titre,statut,exercice_id', 'service:id,code,libelle'])
            ->findOrFail((int) $validated['objectif_operationnel_id']);
        $pao = $objectifOperationnel->pao;
        $serviceId = (int) $objectifOperationnel->service_id;

        $this->denyUnlessManagePta(
            $user,
            (int) $pao->direction_id,
            $serviceId
        );

        $existingPta = Pta::query()
            ->where('objectif_operationnel_id', (int) $objectifOperationnel->id)
            ->first();

        $pta = Pta::query()->updateOrCreate([
            'objectif_operationnel_id' => (int) $objectifOperationnel->id,
        ], [
            'pao_id' => (int) $pao->id,
            'objectif_operationnel_id' => (int) $objectifOperationnel->id,
            'direction_id' => (int) $pao->direction_id,
            'service_id' => $serviceId,
            'titre' => (string) $validated['titre'],
            'description' => $validated['description'] ?? null,
            'statut' => (string) ($validated['statut'] ?? 'brouillon'),
            'valide_le' => $validated['valide_le'] ?? null,
            'valide_par' => $validated['valide_par'] ?? null,
            'exercice_id' => $pao->exercice_id,
        ]);
        $this->recordAudit(
            $request,
            'pta',
            $existingPta === null ? 'create' : 'update',
            $pta,
            $existingPta?->toArray(),
            $pta->toArray()
        );

        if ($existingPta === null) {
            $notificationService->notifyPtaCreatedToDirection($pta, $user);
        }

        return response()->json([
            'message' => $existingPta === null
                ? $this->entityCreatedMessage(UiLabel::object('pta'))
                : $this->entityUpdatedMessage(UiLabel::object('pta')),
            'data' => $pta->load([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'objectifOperationnel:id,pao_id,libelle,echeance',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ]),
        ], $existingPta === null ? 201 : 200);
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
                'objectifOperationnel:id,pao_id,libelle,echeance',
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
        $objectifOperationnel = ObjectifOperationnel::query()
            ->with(['pao:id,pas_id,direction_id,service_id,annee,titre,statut,exercice_id', 'service:id,code,libelle'])
            ->findOrFail((int) $validated['objectif_operationnel_id']);
        $targetPao = $objectifOperationnel->pao;
        $targetServiceId = (int) $objectifOperationnel->service_id;

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
            'objectif_operationnel_id' => (int) $objectifOperationnel->id,
            'direction_id' => (int) $targetPao->direction_id,
            'service_id' => $targetServiceId,
            'titre' => (string) $validated['titre'],
            'description' => $validated['description'] ?? null,
            'statut' => (string) ($validated['statut'] ?? $pta->statut),
            'valide_le' => $validated['valide_le'] ?? null,
            'valide_par' => $validated['valide_par'] ?? null,
            'exercice_id' => $targetPao->exercice_id,
        ]);
        $pta->save();

        $this->recordAudit($request, 'pta', 'update', $pta, $before, $pta->toArray());

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pta')),
            'data' => $pta->load([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'objectifOperationnel:id,pao_id,libelle,echeance',
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
