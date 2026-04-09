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
use App\Services\DynamicReferentialSettings;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = Kpi::query()
            ->with([
                'action:id,pta_id,libelle,statut',
                'action.pta:id,direction_id,service_id,titre',
            ])
            ->withCount('mesures');

        $this->scopePlanningKpis($query, $user);

        $query->when(
            $request->filled('action_id'),
            fn ($q) => $q->where('action_id', (int) $request->integer('action_id'))
        );
        $query->when(
            $request->filled('periodicite'),
            fn ($q) => $q->where('periodicite', (string) $request->string('periodicite'))
        );
        $query->when(
            $request->filled('est_a_renseigner'),
            fn ($q) => $q->where('est_a_renseigner', $request->boolean('est_a_renseigner'))
        );
        $query->when(
            $request->boolean('without_mesure'),
            fn ($q) => $q->where('est_a_renseigner', true)->doesntHave('mesures')
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('libelle', 'like', "%{$search}%")
                    ->orWhere('unite', 'like', "%{$search}%");
            });
        });

        return view('workspace.kpi.index', [
            'rows' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'actionOptions' => $this->actionOptions($user),
            'periodiciteOptions' => $this->periodiciteOptions(),
            'modeSaisieOptions' => $this->modeSaisieOptions(),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'action_id' => $request->filled('action_id') ? (int) $request->integer('action_id') : null,
                'periodicite' => (string) $request->string('periodicite'),
                'est_a_renseigner' => $request->filled('est_a_renseigner')
                    ? (int) $request->boolean('est_a_renseigner')
                    : null,
                'without_mesure' => $request->boolean('without_mesure'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        return view('workspace.kpi.form', [
            'mode' => 'create',
            'row' => new Kpi(),
            'actionOptions' => $this->actionOptions($user),
            'periodiciteOptions' => $this->periodiciteOptions(),
            'modeSaisieOptions' => $this->modeSaisieOptions(),
            'unitSuggestions' => app(DynamicReferentialSettings::class)->kpiUnitSuggestions(),
        ]);
    }

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

        if ($action->pta?->statut === 'verrouille') {
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

    public function edit(Request $request, Kpi $kpi): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id');
        $this->denyUnlessWriteService(
            $user,
            (int) $kpi->action?->pta?->direction_id,
            (int) $kpi->action?->pta?->service_id
        );

        return view('workspace.kpi.form', [
            'mode' => 'edit',
            'row' => $kpi,
            'actionOptions' => $this->actionOptions($user),
            'periodiciteOptions' => $this->periodiciteOptions(),
            'modeSaisieOptions' => $this->modeSaisieOptions(),
            'unitSuggestions' => app(DynamicReferentialSettings::class)->kpiUnitSuggestions(),
        ]);
    }

    public function update(UpdateKpiRequest $request, Kpi $kpi): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id,statut');

        if ($kpi->action?->pta?->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pta'), 'parent', 'Mise a jour'),
            ]);
        }

        $validated = $request->validated();
        $validated['periodicite'] = $validated['periodicite'] ?? 'mensuel';
        $validated['est_a_renseigner'] = $request->boolean('est_a_renseigner', true);
        $targetAction = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if ($targetAction->pta?->statut === 'verrouille') {
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

        if ($kpi->action?->pta?->statut === 'verrouille') {
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Action>
     */
    private function actionOptions(User $user)
    {
        $query = Action::query()
            ->with('pta:id,direction_id,service_id,titre')
            ->orderByDesc('id');

        $this->scopePlanningActions($query, $user);

        return $query->get(['id', 'pta_id', 'libelle', 'statut']);
    }

    /**
     * @return array<int, string>
     */
    private function periodiciteOptions(): array
    {
        return ['mensuel', 'trimestriel', 'semestriel', 'annuel', 'ponctuel'];
    }

    /**
     * @return array<string, string>
     */
    private function modeSaisieOptions(): array
    {
        return [
            '1' => UiLabel::indicatorInputMode(true),
            '0' => UiLabel::indicatorInputMode(false),
        ];
    }
}
