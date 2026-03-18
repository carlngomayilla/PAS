<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasAxeRequest;
use App\Http\Requests\UpdatePasAxeRequest;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasAxeController extends Controller
{
    use AuthorizesPlanningScope;
    use RecordsAuditTrail;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));

        $query = PasAxe::query()
            ->with(['pas:id,titre,periode_debut,periode_fin,statut'])
            ->withCount('objectifs');

        $this->scopePasAxeRead($query, $user);

        $query->when(
            $request->filled('pas_id'),
            fn ($q) => $q->where('pas_id', (int) $request->integer('pas_id'))
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

    public function store(StorePasAxeRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $validated = $request->validated();
        $pas = Pas::query()->findOrFail((int) $validated['pas_id']);

        if ($pas->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PAS parent est verrouille. Creation impossible.',
            ], 409);
        }

        $axe = PasAxe::query()->create($validated);
        $this->recordAudit($request, 'pas_axe', 'create', $axe, null, $axe->toArray());

        return response()->json([
            'message' => 'Axe strategique PAS cree avec succes.',
            'data' => $axe->load(['pas:id,titre,periode_debut,periode_fin,statut']),
        ], 201);
    }

    public function show(Request $request, PasAxe $pasAxe): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadPas($user, (int) $pasAxe->pas_id)) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $pasAxe->load([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'objectifs:id,pas_axe_id,code,libelle',
            ]),
        ]);
    }

    public function update(UpdatePasAxeRequest $request, PasAxe $pasAxe): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $pasAxe->loadMissing('pas:id,statut');
        if ($pasAxe->pas?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PAS parent est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $validated = $request->validated();
        $targetPas = Pas::query()->findOrFail((int) $validated['pas_id']);
        if ($targetPas->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PAS cible est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $before = $pasAxe->toArray();
        $pasAxe->fill($validated);
        $pasAxe->save();

        $this->recordAudit($request, 'pas_axe', 'update', $pasAxe, $before, $pasAxe->toArray());

        return response()->json([
            'message' => 'Axe strategique PAS mis a jour avec succes.',
            'data' => $pasAxe->load(['pas:id,titre,periode_debut,periode_fin,statut']),
        ]);
    }

    public function destroy(Request $request, PasAxe $pasAxe): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $pasAxe->loadMissing('pas:id,statut');
        if ($pasAxe->pas?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PAS parent est verrouille. Suppression impossible.',
            ], 409);
        }

        $before = $pasAxe->toArray();
        $pasAxe->delete();
        $this->recordAudit($request, 'pas_axe', 'delete', $pasAxe, $before, null);

        return response()->json([], 204);
    }

    private function scopePasAxeRead($query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('pas.paos.ptas', fn ($q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }
}
