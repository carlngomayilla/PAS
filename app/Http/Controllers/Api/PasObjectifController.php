<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasObjectifRequest;
use App\Http\Requests\UpdatePasObjectifRequest;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasObjectifController extends Controller
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

        $query = PasObjectif::query()
            ->with(['pasAxe:id,pas_id,code,libelle', 'pasAxe.pas:id,titre,statut']);

        $this->scopePasObjectifRead($query, $user);

        $query->when(
            $request->filled('pas_axe_id'),
            fn ($q) => $q->where('pas_axe_id', (int) $request->integer('pas_axe_id'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('indicateur_global', 'like', "%{$search}%")
                    ->orWhere('valeur_cible', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StorePasObjectifRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $validated = $request->validated();
        $pasAxe = PasAxe::query()->with('pas:id,statut')->findOrFail((int) $validated['pas_axe_id']);

        if ($pasAxe->pas?->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pas'), 'parent', 'Creation'),
            ], 409);
        }

        $objectif = PasObjectif::query()->create($validated);
        $this->recordAudit($request, 'pas_objectif', 'create', $objectif, null, $objectif->toArray());

        return response()->json([
            'message' => $this->entityCreatedMessage(UiLabel::object('pas_objectif')),
            'data' => $objectif->load(['pasAxe:id,pas_id,code,libelle', 'pasAxe.pas:id,titre,statut']),
        ], 201);
    }

    public function show(Request $request, PasObjectif $pasObjectif): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $pasId = $pasObjectif->pasAxe()->value('pas_id');
        if (! $this->canReadPas($user, $pasId !== null ? (int) $pasId : null)) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $pasObjectif->load([
                'pasAxe:id,pas_id,code,libelle',
                'pasAxe.pas:id,titre,statut',
            ]),
        ]);
    }

    public function update(UpdatePasObjectifRequest $request, PasObjectif $pasObjectif): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $pasObjectif->loadMissing('pasAxe.pas:id,statut');
        if ($pasObjectif->pasAxe?->pas?->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pas'), 'parent', 'Mise a jour'),
            ], 409);
        }

        $validated = $request->validated();
        $targetAxe = PasAxe::query()->with('pas:id,statut')->findOrFail((int) $validated['pas_axe_id']);
        if ($targetAxe->pas?->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pas'), 'cible', 'Mise a jour'),
            ], 409);
        }

        $before = $pasObjectif->toArray();
        $pasObjectif->fill($validated);
        $pasObjectif->save();

        $this->recordAudit($request, 'pas_objectif', 'update', $pasObjectif, $before, $pasObjectif->toArray());

        return response()->json([
            'message' => $this->entityUpdatedMessage(UiLabel::object('pas_objectif')),
            'data' => $pasObjectif->load(['pasAxe:id,pas_id,code,libelle', 'pasAxe.pas:id,titre,statut']),
        ]);
    }

    public function destroy(Request $request, PasObjectif $pasObjectif): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $pasObjectif->loadMissing('pasAxe.pas:id,statut');
        if ($pasObjectif->pasAxe?->pas?->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pas'), 'parent', 'Suppression'),
            ], 409);
        }

        $before = $pasObjectif->toArray();
        $pasObjectif->delete();
        $this->recordAudit($request, 'pas_objectif', 'delete', $pasObjectif, $before, null);

        return response()->json([], 204);
    }

    private function scopePasObjectifRead($query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('pasAxe', fn ($q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('pasAxe.pas.paos.ptas', fn ($q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }
}
