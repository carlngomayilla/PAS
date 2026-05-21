<?php

namespace App\Http\Controllers\Web;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KpiMesureWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function store(StoreKpiMesureRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $kpi = Kpi::query()
            ->with('action.pta:id,direction_id,service_id,statut')
            ->findOrFail((int) $validated['kpi_id']);

        if ($kpi->action?->pta?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'kpi_id' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Creation'),
            ]);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpi->action?->pta?->direction_id,
            (int) $kpi->action?->pta?->service_id
        );

        if (! array_key_exists('saisi_par', $validated) || $validated['saisi_par'] === null) {
            $validated['saisi_par'] = $user->id;
        }

        $mesure = KpiMesure::query()->create($validated);
        $this->recordAudit($request, 'kpi_mesure', 'create', $mesure, null, $mesure->toArray());

        return redirect()
            ->route('workspace.kpi-mesures.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('kpi_mesure'), true));
    }

    public function update(UpdateKpiMesureRequest $request, KpiMesure $kpiMesure): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');

        if ($kpiMesure->kpi?->action?->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour'),
            ]);
        }

        $validated = $request->validated();
        $targetKpi = Kpi::query()
            ->with('action.pta:id,direction_id,service_id,statut')
            ->findOrFail((int) $validated['kpi_id']);

        if ($targetKpi->action?->pta?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'kpi_id' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'cible', 'Mise a jour'),
            ]);
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
        $kpiMesure->update($validated);

        $this->recordAudit($request, 'kpi_mesure', 'update', $kpiMesure, $before, $kpiMesure->toArray());

        return redirect()
            ->route('workspace.kpi-mesures.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('kpi_mesure')));
    }

    public function destroy(Request $request, KpiMesure $kpiMesure): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');

        if ($kpiMesure->kpi?->action?->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Suppression'),
            ]);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpiMesure->kpi?->action?->pta?->direction_id,
            (int) $kpiMesure->kpi?->action?->pta?->service_id
        );

        $before = $kpiMesure->toArray();
        $kpiMesure->delete();

        $this->recordAudit($request, 'kpi_mesure', 'delete', $kpiMesure, $before, null);

        return redirect()
            ->route('workspace.kpi-mesures.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('kpi_mesure'), true));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_DIRECTION)
            || $user->hasRole(User::ROLE_SERVICE)
            || $user->hasDelegatedPermission('planning_write');
    }

}
