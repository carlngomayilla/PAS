<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKpiRequest;
use App\Http\Requests\UpdateKpiRequest;
use App\Models\Action;
use App\Models\Kpi;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KpiWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function store(StoreKpiRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $validated['periodicite'] = $validated['periodicite'] ?? 'mensuel';
        $validated['est_a_renseigner'] = $request->boolean('est_a_renseigner', true);
        $action = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withInput()->withErrors([
                'action_id' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Creation'),
            ]);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );

        if (! empty($validated['cible']) && ! empty($validated['seuil_alerte'])
            && (float) $validated['seuil_alerte'] > (float) $validated['cible']
        ) {
            return back()->withInput()->withErrors([
                'seuil_alerte' => 'Le seuil d alerte ne doit pas depasser la cible.',
            ]);
        }

        $kpi = Kpi::query()->create($validated);
        $this->recordAudit($request, 'kpi', 'create', $kpi, null, $kpi->toArray());

        return redirect()
            ->route('workspace.kpi.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('kpi')));
    }

    public function update(UpdateKpiRequest $request, Kpi $kpi): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id,statut');

        if (in_array((string) $kpi->action?->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour'),
            ]);
        }

        $validated = $request->validated();
        $validated['periodicite'] = $validated['periodicite'] ?? 'mensuel';
        $validated['est_a_renseigner'] = $request->boolean('est_a_renseigner', true);
        $targetAction = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if (in_array((string) $targetAction->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withInput()->withErrors([
                'action_id' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'cible', 'Mise a jour'),
            ]);
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

        if (! empty($validated['cible']) && ! empty($validated['seuil_alerte'])
            && (float) $validated['seuil_alerte'] > (float) $validated['cible']
        ) {
            return back()->withInput()->withErrors([
                'seuil_alerte' => 'Le seuil d alerte ne doit pas depasser la cible.',
            ]);
        }

        $before = $kpi->toArray();
        $kpi->update($validated);

        $this->recordAudit($request, 'kpi', 'update', $kpi, $before, $kpi->toArray());

        return redirect()
            ->route('workspace.kpi.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('kpi')));
    }

    public function destroy(Request $request, Kpi $kpi): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id,statut');

        if (in_array((string) $kpi->action?->pta?->statut, ['cloture', 'archive'], true)) {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Suppression'),
            ]);
        }

        $this->denyUnlessWriteService(
            $user,
            (int) $kpi->action?->pta?->direction_id,
            (int) $kpi->action?->pta?->service_id
        );

        $before = $kpi->toArray();
        $kpi->delete();

        $this->recordAudit($request, 'kpi', 'delete', $kpi, $before, null);

        return redirect()
            ->route('workspace.kpi.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('kpi')));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_DIRECTION)
            || $user->hasRole(User::ROLE_SERVICE)
            || $user->hasDelegatedPermission('planning_write');
    }

}
