<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoAxeRequest;
use App\Http\Requests\UpdatePaoAxeRequest;
use App\Models\Pao;
use App\Models\PaoAxe;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaoAxeWebController extends Controller
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

        $query = PaoAxe::query()
            ->with(['pao:id,pas_id,direction_id,annee,titre,echeance'])
            ->withCount('objectifsStrategiques');

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

        $query->when(
            $request->filled('pao_id'),
            fn ($q) => $q->where('pao_id', (int) $request->integer('pao_id'))
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        return view('workspace.pao_axes.index', [
            'rows' => $query->orderBy('ordre')->orderByDesc('id')->paginate(15)->withQueryString(),
            'paoOptions' => $this->paoOptions($user),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pao_id' => $request->filled('pao_id') ? (int) $request->integer('pao_id') : null,
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

        return view('workspace.pao_axes.form', [
            'mode' => 'create',
            'row' => new PaoAxe(),
            'paoOptions' => $this->paoOptions($user),
        ]);
    }

    public function store(StorePaoAxeRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $pao = Pao::query()->findOrFail((int) $validated['pao_id']);

        $this->denyUnlessWriteDirection($user, (int) $pao->direction_id);

        if ($pao->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pao_id' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'parent', 'Creation'),
            ]);
        }

        $axe = PaoAxe::query()->create($validated);
        $this->recordAudit($request, 'pao_axe', 'create', $axe, null, $axe->toArray());

        return redirect()
            ->route('workspace.pao-axes.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('pao_axe')));
    }

    public function edit(Request $request, PaoAxe $paoAxe): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $directionId = $paoAxe->pao()->value('direction_id');
        $this->denyUnlessWriteDirection($user, $directionId !== null ? (int) $directionId : null);

        return view('workspace.pao_axes.form', [
            'mode' => 'edit',
            'row' => $paoAxe,
            'paoOptions' => $this->paoOptions($user),
        ]);
    }

    public function update(UpdatePaoAxeRequest $request, PaoAxe $paoAxe): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $currentPao = $paoAxe->pao()->first();
        if ($currentPao === null) {
            abort(404);
        }

        if ($currentPao->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'parent', 'Mise a jour'),
            ]);
        }

        $this->denyUnlessWriteDirection($user, (int) $currentPao->direction_id);

        $validated = $request->validated();
        if ((int) $validated['pao_id'] !== (int) $paoAxe->pao_id) {
            $targetPao = Pao::query()->findOrFail((int) $validated['pao_id']);
            $this->denyUnlessWriteDirection($user, (int) $targetPao->direction_id);
            if ($targetPao->statut === 'verrouille') {
                return back()->withInput()->withErrors([
                    'pao_id' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'cible', 'Mise a jour'),
                ]);
            }
        }

        $before = $paoAxe->toArray();
        $paoAxe->update($validated);
        $this->recordAudit($request, 'pao_axe', 'update', $paoAxe, $before, $paoAxe->toArray());

        return redirect()
            ->route('workspace.pao-axes.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pao_axe')));
    }

    public function destroy(Request $request, PaoAxe $paoAxe): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $pao = $paoAxe->pao()->first();
        if ($pao === null) {
            abort(404);
        }

        $this->denyUnlessWriteDirection($user, (int) $pao->direction_id);

        if ($pao->statut === 'verrouille') {
            return back()->withErrors([
                'general' => $this->lockedRelatedStateMessage(UiLabel::object('pao'), 'parent', 'Suppression'),
            ]);
        }

        $before = $paoAxe->toArray();
        $paoAxe->delete();
        $this->recordAudit($request, 'pao_axe', 'delete', $paoAxe, $before, null);

        return redirect()
            ->route('workspace.pao-axes.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pao_axe')));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Pao>
     */
    private function paoOptions(User $user)
    {
        $query = Pao::query()
            ->with('direction:id,code,libelle')
            ->orderByDesc('annee')
            ->orderByDesc('id');

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('ptas', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
        } else {
            $this->scopeByUserDirection($query, $user, 'direction_id');
        }

        return $query->get(['id', 'direction_id', 'annee', 'titre', 'statut']);
    }
}
