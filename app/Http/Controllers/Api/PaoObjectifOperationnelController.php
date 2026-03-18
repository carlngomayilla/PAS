<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoObjectifOperationnelRequest;
use App\Http\Requests\UpdatePaoObjectifOperationnelRequest;
use App\Models\PaoObjectifOperationnel;
use App\Models\PaoObjectifStrategique;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaoObjectifOperationnelController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(PaoObjectifOperationnel::class, 'paoObjectifOperationnel');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaoObjectifOperationnel::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));
        $user = $request->user();

        $query = PaoObjectifOperationnel::query()
            ->with([
                'objectifStrategique:id,pao_axe_id,code,libelle',
                'responsable:id,name,email',
            ]);

        if ($user instanceof User && $user->hasRole(User::ROLE_DIRECTION)) {
            if ($user->direction_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('objectifStrategique.paoAxe.pao', function ($subQuery) use ($user): void {
                    $subQuery->where('direction_id', (int) $user->direction_id);
                });
            }
        } elseif ($user instanceof User && $user->hasRole(User::ROLE_SERVICE)) {
            if ($user->service_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('objectifStrategique.paoAxe.pao.ptas', function ($subQuery) use ($user): void {
                    $subQuery->where('service_id', (int) $user->service_id);
                });
            }
        }

        $query->when(
            $request->filled('pao_objectif_strategique_id'),
            fn ($q) => $q->where(
                'pao_objectif_strategique_id',
                (int) $request->integer('pao_objectif_strategique_id')
            )
        );

        $query->when(
            $request->filled('responsable_id'),
            fn ($q) => $q->where('responsable_id', (int) $request->integer('responsable_id'))
        );

        $query->when(
            $request->filled('statut_realisation'),
            fn ($q) => $q->where('statut_realisation', (string) $request->string('statut_realisation'))
        );

        $query->when(
            $request->filled('priorite'),
            fn ($q) => $q->where('priorite', (string) $request->string('priorite'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));

            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%")
                    ->orWhere('description_action_detaillee', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StorePaoObjectifOperationnelRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $objectifStrategique = PaoObjectifStrategique::query()
            ->findOrFail((int) $validated['pao_objectif_strategique_id']);

        $this->authorize(
            'createForObjectifStrategique',
            [PaoObjectifOperationnel::class, $objectifStrategique]
        );

        $objectif = PaoObjectifOperationnel::create($validated);

        return response()->json([
            'message' => 'Objectif operationnel cree avec succes.',
            'data' => $objectif->load([
                'objectifStrategique:id,pao_axe_id,code,libelle',
                'responsable:id,name,email',
            ]),
        ], 201);
    }

    public function show(PaoObjectifOperationnel $paoObjectifOperationnel): JsonResponse
    {
        return response()->json([
            'data' => $paoObjectifOperationnel->load([
                'objectifStrategique:id,pao_axe_id,code,libelle',
                'responsable:id,name,email',
            ]),
        ]);
    }

    public function update(
        UpdatePaoObjectifOperationnelRequest $request,
        PaoObjectifOperationnel $paoObjectifOperationnel
    ): JsonResponse {
        $validated = $request->validated();

        if ((int) $validated['pao_objectif_strategique_id'] !== (int) $paoObjectifOperationnel->pao_objectif_strategique_id) {
            $targetObjectifStrategique = PaoObjectifStrategique::query()
                ->findOrFail((int) $validated['pao_objectif_strategique_id']);

            $this->authorize(
                'createForObjectifStrategique',
                [PaoObjectifOperationnel::class, $targetObjectifStrategique]
            );
        }

        $paoObjectifOperationnel->fill($validated);
        $paoObjectifOperationnel->save();

        return response()->json([
            'message' => 'Objectif operationnel mis a jour avec succes.',
            'data' => $paoObjectifOperationnel->load([
                'objectifStrategique:id,pao_axe_id,code,libelle',
                'responsable:id,name,email',
            ]),
        ]);
    }

    public function destroy(PaoObjectifOperationnel $paoObjectifOperationnel): JsonResponse
    {
        $paoObjectifOperationnel->delete();

        return response()->json([], 204);
    }
}
