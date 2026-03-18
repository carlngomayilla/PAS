<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKpiRequest;
use App\Http\Requests\UpdateKpiRequest;
use App\Models\Action;
use App\Models\Kpi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiController extends Controller
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

        $query = Kpi::query()
            ->with([
                'action:id,pta_id,libelle,statut',
                'action.pta:id,direction_id,service_id,titre',
            ])
            ->withCount('mesures');

        if (! $user->hasGlobalReadAccess()) {
            if ($user->hasRole(User::ROLE_DIRECTION)) {
                if ($user->direction_id === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('action.pta', function ($subQuery) use ($user): void {
                        $subQuery->where('direction_id', (int) $user->direction_id);
                    });
                }
            } elseif ($user->hasRole(User::ROLE_SERVICE)) {
                if ($user->service_id === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('action.pta', function ($subQuery) use ($user): void {
                        $subQuery->where('service_id', (int) $user->service_id);
                    });
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $query->when(
            $request->filled('action_id'),
            fn ($q) => $q->where('action_id', (int) $request->integer('action_id'))
        );

        $query->when(
            $request->filled('periodicite'),
            fn ($q) => $q->where('periodicite', (string) $request->string('periodicite'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('libelle', 'like', "%{$search}%")
                    ->orWhere('unite', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StoreKpiRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $action = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if ($action->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Creation impossible.',
            ], 409);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        $kpi = Kpi::query()->create($validated);
        $this->recordAudit($request, 'kpi', 'create', $kpi, null, $kpi->toArray());

        return response()->json([
            'message' => 'KPI cree avec succes.',
            'data' => $kpi->load([
                'action:id,pta_id,libelle,statut',
                'action.pta:id,direction_id,service_id,titre',
            ]),
        ], 201);
    }

    public function show(Request $request, Kpi $kpi): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id');

        if (! $this->canReadDirection($user, (int) $kpi->action?->pta?->direction_id)) {
            abort(403, 'Acces non autorise.');
        }

        if ($user->hasRole(User::ROLE_SERVICE)
            && (int) $user->service_id !== (int) $kpi->action?->pta?->service_id
        ) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $kpi->load([
                'action:id,pta_id,libelle,statut',
                'action.pta:id,direction_id,service_id,titre',
                'mesures:id,kpi_id,periode,valeur,saisi_par',
            ]),
        ]);
    }

    public function update(UpdateKpiRequest $request, Kpi $kpi): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id,statut');

        if ($kpi->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $validated = $request->validated();
        $targetAction = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if ($targetAction->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA cible est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpi->action?->pta?->direction_id,
            (int) $kpi->action?->pta?->service_id
        );

        $this->denyUnlessWriteService(
            $user,
            (int) $targetAction->pta?->direction_id,
            (int) $targetAction->pta?->service_id
        );

        $before = $kpi->toArray();
        $kpi->fill($validated);
        $kpi->save();

        $this->recordAudit($request, 'kpi', 'update', $kpi, $before, $kpi->toArray());

        return response()->json([
            'message' => 'KPI mis a jour avec succes.',
            'data' => $kpi->load([
                'action:id,pta_id,libelle,statut',
                'action.pta:id,direction_id,service_id,titre',
            ]),
        ]);
    }

    public function destroy(Request $request, Kpi $kpi): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id,statut');

        if ($kpi->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Suppression impossible.',
            ], 409);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpi->action?->pta?->direction_id,
            (int) $kpi->action?->pta?->service_id
        );

        $before = $kpi->toArray();
        $kpi->delete();
        $this->recordAudit($request, 'kpi', 'delete', $kpi, $before, null);

        return response()->json([], 204);
    }
}
