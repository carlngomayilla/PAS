<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKpiMesureRequest;
use App\Http\Requests\UpdateKpiMesureRequest;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiMesureWebController extends Controller
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

        $query = KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,periodicite',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre,statut',
                'saisiPar:id,name,email',
            ]);

        $this->scopeKpiMesure($query, $user);

        $query->when(
            $request->filled('kpi_id'),
            fn ($q) => $q->where('kpi_id', (int) $request->integer('kpi_id'))
        );
        $query->when(
            $request->filled('periode'),
            fn ($q) => $q->where('periode', 'like', '%' . trim((string) $request->string('periode')) . '%')
        );
        $query->when(
            $request->filled('saisi_par'),
            fn ($q) => $q->where('saisi_par', (int) $request->integer('saisi_par'))
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('periode', 'like', "%{$search}%")
                    ->orWhere('commentaire', 'like', "%{$search}%");
            });
        });

        return view('workspace.kpi_mesures.index', [
            'rows' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'kpiOptions' => $this->kpiOptions($user),
            'saisiParOptions' => $this->saisiParOptions($user),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'kpi_id' => $request->filled('kpi_id') ? (int) $request->integer('kpi_id') : null,
                'periode' => (string) $request->string('periode'),
                'saisi_par' => $request->filled('saisi_par') ? (int) $request->integer('saisi_par') : null,
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

        return view('workspace.kpi_mesures.form', [
            'mode' => 'create',
            'row' => new KpiMesure(),
            'kpiOptions' => $this->kpiOptions($user),
            'saisiParOptions' => $this->saisiParOptions($user),
        ]);
    }

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
                'kpi_id' => 'Le PTA parent est verrouille. Creation impossible.',
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
            ->with('success', 'Mesure KPI creee avec succes.');
    }

    public function edit(Request $request, KpiMesure $kpiMesure): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id');
        $this->denyUnlessWriteService(
            $user,
            (int) $kpiMesure->kpi?->action?->pta?->direction_id,
            (int) $kpiMesure->kpi?->action?->pta?->service_id
        );

        return view('workspace.kpi_mesures.form', [
            'mode' => 'edit',
            'row' => $kpiMesure,
            'kpiOptions' => $this->kpiOptions($user),
            'saisiParOptions' => $this->saisiParOptions($user),
        ]);
    }

    public function update(UpdateKpiMesureRequest $request, KpiMesure $kpiMesure): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');

        if ($kpiMesure->kpi?->action?->pta?->statut === 'verrouille') {
            return back()->withErrors(['general' => 'Le PTA parent est verrouille. Mise a jour impossible.']);
        }

        $validated = $request->validated();
        $targetKpi = Kpi::query()
            ->with('action.pta:id,direction_id,service_id,statut')
            ->findOrFail((int) $validated['kpi_id']);

        if ($targetKpi->action?->pta?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'kpi_id' => 'Le PTA cible est verrouille. Mise a jour impossible.',
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
            ->with('success', 'Mesure KPI mise a jour avec succes.');
    }

    public function destroy(Request $request, KpiMesure $kpiMesure): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $kpiMesure->loadMissing('kpi.action.pta:id,direction_id,service_id,statut');

        if ($kpiMesure->kpi?->action?->pta?->statut === 'verrouille') {
            return back()->withErrors(['general' => 'Le PTA parent est verrouille. Suppression impossible.']);
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
            ->with('success', 'Mesure KPI supprimee avec succes.');
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_DIRECTION)
            || $user->hasRole(User::ROLE_SERVICE);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Kpi>
     */
    private function kpiOptions(User $user)
    {
        $query = Kpi::query()
            ->with('action.pta:id,direction_id,service_id,titre,statut')
            ->orderByDesc('id');

        $this->scopeKpi($query, $user);

        return $query->get(['id', 'action_id', 'libelle', 'periodicite']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function saisiParOptions(User $user)
    {
        $query = User::query()->orderBy('name');

        if (! $user->hasGlobalReadAccess() && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query->get(['id', 'name', 'email', 'direction_id', 'service_id']);
    }

    private function scopeKpiMesure(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
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
}
