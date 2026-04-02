<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoObjectifStrategiqueRequest;
use App\Http\Requests\UpdatePaoObjectifStrategiqueRequest;
use App\Models\PaoAxe;
use App\Models\PaoObjectifStrategique;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaoObjectifStrategiqueWebController extends Controller
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

        $query = PaoObjectifStrategique::query()
            ->with([
                'paoAxe:id,pao_id,code,libelle',
                'paoAxe.pao:id,direction_id,annee,titre,statut',
            ])
            ->withCount('objectifsOperationnels');

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            if ($user->direction_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('paoAxe.pao', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            }
        } elseif ($user->hasRole(User::ROLE_SERVICE)) {
            if ($user->service_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('paoAxe.pao.ptas', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            }
        }

        $query->when(
            $request->filled('pao_axe_id'),
            fn ($q) => $q->where('pao_axe_id', (int) $request->integer('pao_axe_id'))
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        return view('workspace.pao_objectifs_strategiques.index', [
            'rows' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'paoAxeOptions' => $this->paoAxeOptions($user),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pao_axe_id' => $request->filled('pao_axe_id') ? (int) $request->integer('pao_axe_id') : null,
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

        return view('workspace.pao_objectifs_strategiques.form', [
            'mode' => 'create',
            'row' => new PaoObjectifStrategique(),
            'paoAxeOptions' => $this->paoAxeOptions($user),
        ]);
    }

    public function store(StorePaoObjectifStrategiqueRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $axe = PaoAxe::query()->with('pao:id,direction_id,statut')->findOrFail((int) $validated['pao_axe_id']);

        $this->denyUnlessWriteDirection($user, (int) $axe->pao?->direction_id);

        if ($axe->pao?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pao_axe_id' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'parent', 'Creation'),
            ]);
        }

        $objectif = PaoObjectifStrategique::query()->create($validated);
        $this->recordAudit(
            $request,
            'pao_objectif_strategique',
            'create',
            $objectif,
            null,
            $objectif->toArray()
        );

        return redirect()
            ->route('workspace.pao-objectifs-strategiques.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('pao_objectif_strategique')));
    }

    public function edit(Request $request, PaoObjectifStrategique $paoObjectifStrategique): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $currentAxe = $paoObjectifStrategique->paoAxe()->with('pao:id,direction_id')->first();
        if ($currentAxe === null || $currentAxe->pao === null) {
            abort(404);
        }

        $this->denyUnlessWriteDirection($user, (int) $currentAxe->pao->direction_id);

        return view('workspace.pao_objectifs_strategiques.form', [
            'mode' => 'edit',
            'row' => $paoObjectifStrategique,
            'paoAxeOptions' => $this->paoAxeOptions($user),
        ]);
    }

    public function update(
        UpdatePaoObjectifStrategiqueRequest $request,
        PaoObjectifStrategique $paoObjectifStrategique
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $currentAxe = $paoObjectifStrategique->paoAxe()->with('pao:id,direction_id,statut')->first();
        if ($currentAxe === null || $currentAxe->pao === null) {
            abort(404);
        }

        if ($currentAxe->pao->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'parent', 'Mise a jour'),
            ]);
        }

        $this->denyUnlessWriteDirection($user, (int) $currentAxe->pao->direction_id);

        $validated = $request->validated();
        $targetAxe = PaoAxe::query()->with('pao:id,direction_id,statut')->findOrFail((int) $validated['pao_axe_id']);

        $this->denyUnlessWriteDirection($user, (int) $targetAxe->pao?->direction_id);

        if ($targetAxe->pao?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pao_axe_id' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'cible', 'Mise a jour'),
            ]);
        }

        $before = $paoObjectifStrategique->toArray();
        $paoObjectifStrategique->update($validated);

        $this->recordAudit(
            $request,
            'pao_objectif_strategique',
            'update',
            $paoObjectifStrategique,
            $before,
            $paoObjectifStrategique->toArray()
        );

        return redirect()
            ->route('workspace.pao-objectifs-strategiques.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pao_objectif_strategique')));
    }

    public function destroy(Request $request, PaoObjectifStrategique $paoObjectifStrategique): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $currentAxe = $paoObjectifStrategique->paoAxe()->with('pao:id,direction_id,statut')->first();
        if ($currentAxe === null || $currentAxe->pao === null) {
            abort(404);
        }

        if ($currentAxe->pao->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'parent', 'Suppression'),
            ]);
        }

        $this->denyUnlessWriteDirection($user, (int) $currentAxe->pao->direction_id);

        $before = $paoObjectifStrategique->toArray();
        $paoObjectifStrategique->delete();

        $this->recordAudit(
            $request,
            'pao_objectif_strategique',
            'delete',
            $paoObjectifStrategique,
            $before,
            null
        );

        return redirect()
            ->route('workspace.pao-objectifs-strategiques.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pao_objectif_strategique')));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PaoAxe>
     */
    private function paoAxeOptions(User $user)
    {
        $query = PaoAxe::query()
            ->with('pao:id,direction_id,annee,titre,statut')
            ->orderByDesc('id');

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            if ($user->direction_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('pao', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            }
        } elseif ($user->hasRole(User::ROLE_SERVICE)) {
            if ($user->service_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('pao.ptas', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            }
        }

        return $query->get(['id', 'pao_id', 'code', 'libelle']);
    }
}
