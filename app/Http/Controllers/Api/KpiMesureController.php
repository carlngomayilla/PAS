<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKpiMesureRequest;
use App\Http\Requests\UpdateKpiMesureRequest;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiMesureController extends Controller
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

        $query = KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,periodicite,est_a_renseigner',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre',
                'saisiPar:id,name,email',
            ]);

        $this->scopePlanningKpiMesures($query, $user);

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
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Creation'),
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
            'message' => $this->entityCreatedMessage(UiLabel::object('kpi_mesure'), true),
            'data' => $mesure->load([
                'kpi:id,action_id,libelle,periodicite,est_a_renseigner',
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

        if (! $this->canReadService($user, (int) $kpiMesure->kpi?->action?->pta?->direction_id, (int) $kpiMesure->kpi?->action?->pta?->service_id)) {
            abort(403, 'Acces non autorise.');
        }

        return response()->json([
            'data' => $kpiMesure->load([
                'kpi:id,action_id,libelle,periodicite,est_a_renseigner',
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
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour'),
            ], 409);
        }

        $validated = $request->validated();
        $targetKpi = Kpi::query()->with('action.pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['kpi_id']);

        if ($targetKpi->action?->pta?->statut === 'verrouille') {
            return response()->json([
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'cible', 'Mise a jour'),
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
            'message' => $this->entityUpdatedMessage(UiLabel::object('kpi_mesure')),
            'data' => $kpiMesure->load([
                'kpi:id,action_id,libelle,periodicite,est_a_renseigner',
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
                'message' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Suppression'),
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
