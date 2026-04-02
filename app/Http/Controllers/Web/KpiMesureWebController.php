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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiMesureWebController extends Controller
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

        $query = KpiMesure::query()
            ->with([
                'kpi:id,action_id,libelle,periodicite,est_a_renseigner',
                'kpi.action:id,pta_id,libelle',
                'kpi.action.pta:id,direction_id,service_id,titre,statut',
                'saisiPar:id,name,email',
            ]);

        $this->scopePlanningKpiMesures($query, $user);

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
        $query->when(
            $request->boolean('below_threshold'),
            fn ($q) => $q->whereHas('kpi', fn (Builder $kpiQuery) => $kpiQuery
                ->whereNotNull('seuil_alerte')
                ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte'))
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
            'kpiOptions' => $this->kpiFilterOptions($user),
            'saisiParOptions' => $this->saisiParOptions($user),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'kpi_id' => $request->filled('kpi_id') ? (int) $request->integer('kpi_id') : null,
                'periode' => (string) $request->string('periode'),
                'saisi_par' => $request->filled('saisi_par') ? (int) $request->integer('saisi_par') : null,
                'below_threshold' => $request->boolean('below_threshold'),
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
            'kpiOptions' => $this->kpiFormOptions($user),
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
            'kpiOptions' => $this->kpiFormOptions($user, $kpiMesure->kpi),
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Kpi>
     */
    private function kpiFilterOptions(User $user)
    {
        $query = Kpi::query()
            ->with('action.pta:id,direction_id,service_id,titre,statut')
            ->orderByDesc('id');

        $this->scopePlanningKpis($query, $user);

        return $query->get(['id', 'action_id', 'libelle', 'periodicite', 'est_a_renseigner']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Kpi>
     */
    private function kpiFormOptions(User $user, ?Kpi $currentKpi = null)
    {
        $query = Kpi::query()
            ->with('action.pta:id,direction_id,service_id,titre,statut')
            ->orderByDesc('id');

        $this->scopePlanningKpis($query, $user);

        $query->where(function (Builder $builder) use ($currentKpi): void {
            $builder->where('est_a_renseigner', true);

            if ($currentKpi instanceof Kpi) {
                $builder->orWhereKey($currentKpi->getKey());
            }
        });

        return $query->get(['id', 'action_id', 'libelle', 'periodicite', 'est_a_renseigner']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function saisiParOptions(User $user)
    {
        $query = User::query()->orderBy('name');

        $this->scopePlanningUsers($query, $user);

        return $query->get(['id', 'name', 'email', 'direction_id', 'service_id']);
    }
}
