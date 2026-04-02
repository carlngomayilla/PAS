<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoObjectifStrategiqueRequest;
use App\Http\Requests\UpdatePaoObjectifStrategiqueRequest;
use App\Models\PaoAxe;
use App\Models\PaoObjectifStrategique;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaoObjectifStrategiqueController extends Controller
{
    use FormatsWorkflowMessages;

    public function __construct()
    {
        $this->authorizeResource(PaoObjectifStrategique::class, 'paoObjectifStrategique');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaoObjectifStrategique::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));
        $user = $request->user();

        $query = PaoObjectifStrategique::query()
            ->with(['paoAxe:id,pao_id,code,libelle']);

        if ($user instanceof User && $user->hasRole(User::ROLE_DIRECTION)) {
            if ($user->direction_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('paoAxe.pao', function ($subQuery) use ($user): void {
                    $subQuery->where('direction_id', (int) $user->direction_id);
                });
            }
        } elseif ($user instanceof User && $user->hasRole(User::ROLE_SERVICE)) {
            if ($user->service_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('paoAxe.pao.ptas', function ($subQuery) use ($user): void {
                    $subQuery->where('service_id', (int) $user->service_id);
                });
            }
        }

        $query->when(
            $request->filled('pao_axe_id'),
            fn ($q) => $q->where('pao_axe_id', (int) $request->integer('pao_axe_id'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));

            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StorePaoObjectifStrategiqueRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $paoAxe = PaoAxe::query()->findOrFail((int) $validated['pao_axe_id']);

        $this->authorize('createForAxe', [PaoObjectifStrategique::class, $paoAxe]);

        $objectifStrategique = PaoObjectifStrategique::create($validated);

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pao_objectif_strategique')),
            'data' => $objectifStrategique->load(['paoAxe:id,pao_id,code,libelle']),
        ], 201);
    }

    public function show(PaoObjectifStrategique $paoObjectifStrategique): JsonResponse
    {
        return response()->json([
            'data' => $paoObjectifStrategique->load(['paoAxe:id,pao_id,code,libelle']),
        ]);
    }

    public function update(
        UpdatePaoObjectifStrategiqueRequest $request,
        PaoObjectifStrategique $paoObjectifStrategique
    ): JsonResponse {
        $validated = $request->validated();

        if ((int) $validated['pao_axe_id'] !== (int) $paoObjectifStrategique->pao_axe_id) {
            $targetAxe = PaoAxe::query()->findOrFail((int) $validated['pao_axe_id']);
            $this->authorize('createForAxe', [PaoObjectifStrategique::class, $targetAxe]);
        }

        $paoObjectifStrategique->fill($validated);
        $paoObjectifStrategique->save();

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pao_objectif_strategique')),
            'data' => $paoObjectifStrategique->load(['paoAxe:id,pao_id,code,libelle']),
        ]);
    }

    public function destroy(PaoObjectifStrategique $paoObjectifStrategique): JsonResponse
    {
        $paoObjectifStrategique->delete();

        return response()->json([], 204);
    }
}
