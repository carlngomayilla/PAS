<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKpiMesureRequest;
use App\Http\Requests\UpdateKpiMesureRequest;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiMesureController extends Controller
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

        $query = KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,periodicite',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'saisiPar:id,name,email',
            ]);

        if (! $user->hasGlobalReadAccess()) {
            if ($user->hasRole(User::ROLE_DIRECTION)) {
                if ($user->direction_id === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('kpi.action.pta', function ($subQuery) use ($user): void {
                        $subQuery->where('direction_id', (int) $user->direction_id);
                    });
                }
            } elseif ($user->hasRole(User::ROLE_SERVICE)) {
                if ($user->service_id === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('kpi.action.pta', function ($subQuery) use ($user): void {
                        $subQuery->where('service_id', (int) $user->service_id);
                    });
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $query->when(
            $request->filled('kpi_id'),
            fn ($q) => $q->where('kpi_id', (int) $request->integer('kpi_id'))
        );

        $query->when(
            $request->filled('periode'),
            fn ($q) => $q->where('periode', (string) $request->string('periode'))
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('periode', 'like', "%{$search}%")
                    ->orWhere('commentaire', 'like', "%{$search}%");
            });
        });

        $result = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($result);
    }

    public function store(StoreKpiMesureRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validated();
        $kpi = Kpi::query()->with('action.pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['kpi_id']);

        if ($kpi->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Creation impossible.',
            ], 409);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpi->action?->pta?->direction_id,
            (int) $kpi->action?->pta?->service_id
        );

        if (! isset($validated['saisi_par'])) {
            $validated['saisi_par'] = $user->id;
        }

        $mesure = KpiMesure::query()->create($validated);
        $this->recordAudit($request, 'kpi_mesure', 'create', $mesure, null, $mesure->toArray());

        return response()->json([
            'message' => 'Mesure KPI creee avec succes.',
            'data' => $mesure->load([
                'kpi:id,action_id,libelle,periodicite',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'saisiPar:id,name,email',
            ]),
        ], 201);
    }

    public function show(Request $request, KpiMesure $kpiMesure): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id');

        if (! $this->canReadDirection($user, (int) $kpiMesure->kpi?->action?->pta?->direction_id)) {
            abort(403, 'Acces non autorise.');
        }

        if ($user->hasRole(User::ROLE_SERVICE)
            && (int) $user->service_id !== (int) $kpiMesure->kpi?->action?->pta?->service_id
        ) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $kpiMesure->load([
                'kpi:id,action_id,libelle,periodicite',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'saisiPar:id,name,email',
            ]),
        ]);
    }

    public function update(UpdateKpiMesureRequest $request, KpiMesure $kpiMesure): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');

        if ($kpiMesure->kpi?->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $validated = $request->validated();
        $targetKpi = Kpi::query()->with('action.pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['kpi_id']);

        if ($targetKpi->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA cible est verrouille. Mise a jour impossible.',
            ], 409);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpiMesure->kpi?->action?->pta?->direction_id,
            (int) $kpiMesure->kpi?->action?->pta?->service_id
        );

        $this->denyUnlessWriteService(
            $user,
            (int) $targetKpi->action?->pta?->direction_id,
            (int) $targetKpi->action?->pta?->service_id
        );

        $before = $kpiMesure->toArray();
        $kpiMesure->fill($validated);
        $kpiMesure->save();

        $this->recordAudit($request, 'kpi_mesure', 'update', $kpiMesure, $before, $kpiMesure->toArray());

        return response()->json([
            'message' => 'Mesure KPI mise a jour avec succes.',
            'data' => $kpiMesure->load([
                'kpi:id,action_id,libelle,periodicite',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'saisiPar:id,name,email',
            ]),
        ]);
    }

    public function destroy(Request $request, KpiMesure $kpiMesure): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');

        if ($kpiMesure->kpi?->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => 'Le PTA parent est verrouille. Suppression impossible.',
            ], 409);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpiMesure->kpi?->action?->pta?->direction_id,
            (int) $kpiMesure->kpi?->action?->pta?->service_id
        );

        $before = $kpiMesure->toArray();
        $kpiMesure->delete();
        $this->recordAudit($request, 'kpi_mesure', 'delete', $kpiMesure, $before, null);

        return response()->json([], 204);
    }
}
