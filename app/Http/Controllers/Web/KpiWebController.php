<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKpiRequest;
use App\Http\Requests\UpdateKpiRequest;
use App\Models\Action;
use App\Models\Kpi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiWebController extends Controller
{
    use AuthorizesPlanningScope;
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

        $this->scopeKpi($query, $user);

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

        return view('workspace.kpi.index', [
            'rows' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'actionOptions' => $this->actionOptions($user),
            'periodiciteOptions' => $this->periodiciteOptions(),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'action_id' => $request->filled('action_id') ? (int) $request->integer('action_id') : null,
                'periodicite' => (string) $request->string('periodicite'),
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
        $action = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if ($action->pta?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'action_id' => 'Le PTA parent est verrouille. Creation impossible.',
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
            ->with('success', 'KPI cree avec succes.');
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
            return back()->withErrors(['general' => 'Le PTA parent est verrouille. Mise a jour impossible.']);
        }

        $validated = $request->validated();
        $validated['periodicite'] = $validated['periodicite'] ?? 'mensuel';
        $targetAction = Action::query()->with('pta:id,direction_id,service_id,statut')->findOrFail((int) $validated['action_id']);

        if ($targetAction->pta?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'action_id' => 'Le PTA cible est verrouille. Mise a jour impossible.',
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
            ->with('success', 'KPI mis a jour avec succes.');
    }

    public function destroy(Request $request, Kpi $kpi): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpi->loadMissing('action.pta:id,direction_id,service_id,statut');

        if ($kpi->action?->pta?->statut === 'verrouille') {
            return back()->withErrors(['general' => 'Le PTA parent est verrouille. Suppression impossible.']);
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
            ->with('success', 'KPI supprime avec succes.');
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_DIRECTION)
            || $user->hasRole(User::ROLE_SERVICE);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Action>
     */
    private function actionOptions(User $user)
    {
        $query = Action::query()
            ->with('pta:id,direction_id,service_id,titre')
            ->orderByDesc('id');

        $this->scopeAction($query, $user);

        return $query->get(['id', 'pta_id', 'libelle', 'statut']);
    }

    /**
     * @return array<int, string>
     */
    private function periodiciteOptions(): array
    {
        return ['mensuel', 'trimestriel', 'semestriel', 'annuel', 'ponctuel'];
    }

    private function scopeKpi(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeAction(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }
}
