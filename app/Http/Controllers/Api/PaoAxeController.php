<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoAxeRequest;
use App\Http\Requests\UpdatePaoAxeRequest;
use App\Models\Pao;
use App\Models\PaoAxe;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaoAxeController extends Controller
{
    use FormatsWorkflowMessages;

    public function __construct()
    {
        $this->authorizeResource(PaoAxe::class, 'paoAxe');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaoAxe::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));
        $user = $request->user();

        $query = PaoAxe::query()
            ->with(['pao:id,pas_id,direction_id,annee,titre,echeance']);

        if ($user instanceof User && $user->hasRole(User::ROLE_DIRECTION)) {
            if ($user->direction_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('pao', function ($subQuery) use ($user): void {
                    $subQuery->where('direction_id', (int) $user->direction_id);
                });
            }
        } elseif ($user instanceof User && $user->hasRole(User::ROLE_SERVICE)) {
            if ($user->service_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('pao.ptas', function ($subQuery) use ($user): void {
                    $subQuery->where('service_id', (int) $user->service_id);
                });
            }
        }

        $query->when(
            $request->filled('pao_id'),
            fn ($q) => $q->where('pao_id', (int) $request->integer('pao_id'))
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
            ->orderBy('ordre')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StorePaoAxeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $pao = Pao::query()->findOrFail((int) $validated['pao_id']);

        $this->authorize('createForPao', [PaoAxe::class, $pao]);

        $paoAxe = PaoAxe::create($validated);

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pao_axe')),
            'data' => $paoAxe->load(['pao:id,pas_id,direction_id,annee,titre,echeance']),
        ], 201);
    }

    public function show(PaoAxe $paoAxe): JsonResponse
    {
        return response()->json([
            'data' => $paoAxe->load(['pao:id,pas_id,direction_id,annee,titre,echeance']),
        ]);
    }

    public function update(UpdatePaoAxeRequest $request, PaoAxe $paoAxe): JsonResponse
    {
        $validated = $request->validated();

        if ((int) $validated['pao_id'] !== (int) $paoAxe->pao_id) {
            $targetPao = Pao::query()->findOrFail((int) $validated['pao_id']);
            $this->authorize('createForPao', [PaoAxe::class, $targetPao]);
        }

        $paoAxe->fill($validated);
        $paoAxe->save();

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pao_axe')),
            'data' => $paoAxe->load(['pao:id,pas_id,direction_id,annee,titre,echeance']),
        ]);
    }

    public function destroy(PaoAxe $paoAxe): JsonResponse
    {
        $paoAxe->delete();

        return response()->json([], 204);
    }
}
