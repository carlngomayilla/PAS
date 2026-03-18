<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoObjectifOperationnelRequest;
use App\Http\Requests\UpdatePaoObjectifOperationnelRequest;
use App\Models\PaoObjectifOperationnel;
use App\Models\PaoObjectifStrategique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaoObjectifOperationnelWebController extends Controller
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

        $query = PaoObjectifOperationnel::query()
            ->with([
                'objectifStrategique:id,pao_axe_id,code,libelle',
                'objectifStrategique.paoAxe:id,pao_id,code,libelle',
                'objectifStrategique.paoAxe.pao:id,direction_id,annee,titre,statut',
                'responsable:id,name,email,direction_id',
            ]);

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            if ($user->direction_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('objectifStrategique.paoAxe.pao', function (Builder $subQuery) use ($user): void {
                    $subQuery->where('direction_id', (int) $user->direction_id);
                });
            }
        } elseif ($user->hasRole(User::ROLE_SERVICE)) {
            if ($user->service_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('objectifStrategique.paoAxe.pao.ptas', function (Builder $subQuery) use ($user): void {
                    $subQuery->where('service_id', (int) $user->service_id);
                });
            }
        }

        $query->when(
            $request->filled('pao_objectif_strategique_id'),
            fn ($q) => $q->where(
                'pao_objectif_strategique_id',
                (int) $request->integer('pao_objectif_strategique_id')
            )
        );
        $query->when(
            $request->filled('responsable_id'),
            fn ($q) => $q->where('responsable_id', (int) $request->integer('responsable_id'))
        );
        $query->when(
            $request->filled('statut_realisation'),
            fn ($q) => $q->where('statut_realisation', (string) $request->string('statut_realisation'))
        );
        $query->when(
            $request->filled('priorite'),
            fn ($q) => $q->where('priorite', (string) $request->string('priorite'))
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%")
                    ->orWhere('description_action_detaillee', 'like', "%{$search}%")
                    ->orWhere('indicateur_performance', 'like', "%{$search}%")
                    ->orWhere('livrable_attendu', 'like', "%{$search}%");
            });
        });

        return view('workspace.pao_objectifs_operationnels.index', [
            'rows' => $query->orderBy('ordre')->orderByDesc('id')->paginate(15)->withQueryString(),
            'objectifStrategiqueOptions' => $this->objectifStrategiqueOptions($user),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => $this->statusOptions(),
            'prioriteOptions' => $this->prioriteOptions(),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pao_objectif_strategique_id' => $request->filled('pao_objectif_strategique_id')
                    ? (int) $request->integer('pao_objectif_strategique_id')
                    : null,
                'responsable_id' => $request->filled('responsable_id')
                    ? (int) $request->integer('responsable_id')
                    : null,
                'statut_realisation' => (string) $request->string('statut_realisation'),
                'priorite' => (string) $request->string('priorite'),
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

        return view('workspace.pao_objectifs_operationnels.form', [
            'mode' => 'create',
            'row' => new PaoObjectifOperationnel(),
            'objectifStrategiqueOptions' => $this->objectifStrategiqueOptions($user),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => $this->statusOptions(),
            'prioriteOptions' => $this->prioriteOptions(),
        ]);
    }

    public function store(StorePaoObjectifOperationnelRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $objectifStrategique = PaoObjectifStrategique::query()
            ->with('paoAxe.pao:id,direction_id,statut')
            ->findOrFail((int) $validated['pao_objectif_strategique_id']);

        $directionId = $objectifStrategique->paoAxe?->pao?->direction_id;
        $this->denyUnlessWriteDirection($user, $directionId !== null ? (int) $directionId : null);

        if ($objectifStrategique->paoAxe?->pao?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pao_objectif_strategique_id' => 'Le PAO parent est verrouille. Creation impossible.',
            ]);
        }

        $this->validateResponsableDirection($validated, $directionId !== null ? (int) $directionId : null);

        $objectif = PaoObjectifOperationnel::query()->create($validated);
        $this->recordAudit(
            $request,
            'pao_objectif_operationnel',
            'create',
            $objectif,
            null,
            $objectif->toArray()
        );

        return redirect()
            ->route('workspace.pao-objectifs-operationnels.index')
            ->with('success', 'Objectif operationnel cree avec succes.');
    }

    public function edit(Request $request, PaoObjectifOperationnel $paoObjectifOperationnel): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $paoObjectifOperationnel->loadMissing('objectifStrategique.paoAxe.pao:id,direction_id');
        $this->denyUnlessWriteDirection(
            $user,
            (int) $paoObjectifOperationnel->objectifStrategique?->paoAxe?->pao?->direction_id
        );

        return view('workspace.pao_objectifs_operationnels.form', [
            'mode' => 'edit',
            'row' => $paoObjectifOperationnel,
            'objectifStrategiqueOptions' => $this->objectifStrategiqueOptions($user),
            'responsableOptions' => $this->responsableOptions($user),
            'statusOptions' => $this->statusOptions(),
            'prioriteOptions' => $this->prioriteOptions(),
        ]);
    }

    public function update(
        UpdatePaoObjectifOperationnelRequest $request,
        PaoObjectifOperationnel $paoObjectifOperationnel
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $paoObjectifOperationnel->loadMissing('objectifStrategique.paoAxe.pao:id,direction_id,statut');

        if ($paoObjectifOperationnel->objectifStrategique?->paoAxe?->pao?->statut === 'verrouille') {
            return back()->withErrors(['general' => 'Le PAO parent est verrouille. Mise a jour impossible.']);
        }

        $this->denyUnlessWriteDirection(
            $user,
            (int) $paoObjectifOperationnel->objectifStrategique?->paoAxe?->pao?->direction_id
        );

        $validated = $request->validated();
        $targetObjectifStrategique = PaoObjectifStrategique::query()
            ->with('paoAxe.pao:id,direction_id,statut')
            ->findOrFail((int) $validated['pao_objectif_strategique_id']);

        $targetDirectionId = $targetObjectifStrategique->paoAxe?->pao?->direction_id;
        $this->denyUnlessWriteDirection($user, $targetDirectionId !== null ? (int) $targetDirectionId : null);

        if ($targetObjectifStrategique->paoAxe?->pao?->statut === 'verrouille') {
            return back()->withInput()->withErrors([
                'pao_objectif_strategique_id' => 'Le PAO cible est verrouille. Mise a jour impossible.',
            ]);
        }

        $this->validateResponsableDirection($validated, $targetDirectionId !== null ? (int) $targetDirectionId : null);

        $before = $paoObjectifOperationnel->toArray();
        $paoObjectifOperationnel->update($validated);

        $this->recordAudit(
            $request,
            'pao_objectif_operationnel',
            'update',
            $paoObjectifOperationnel,
            $before,
            $paoObjectifOperationnel->toArray()
        );

        return redirect()
            ->route('workspace.pao-objectifs-operationnels.index')
            ->with('success', 'Objectif operationnel mis a jour avec succes.');
    }

    public function destroy(Request $request, PaoObjectifOperationnel $paoObjectifOperationnel): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $paoObjectifOperationnel->loadMissing('objectifStrategique.paoAxe.pao:id,direction_id,statut');

        if ($paoObjectifOperationnel->objectifStrategique?->paoAxe?->pao?->statut === 'verrouille') {
            return back()->withErrors(['general' => 'Le PAO parent est verrouille. Suppression impossible.']);
        }

        $this->denyUnlessWriteDirection(
            $user,
            (int) $paoObjectifOperationnel->objectifStrategique?->paoAxe?->pao?->direction_id
        );

        $before = $paoObjectifOperationnel->toArray();
        $paoObjectifOperationnel->delete();

        $this->recordAudit(
            $request,
            'pao_objectif_operationnel',
            'delete',
            $paoObjectifOperationnel,
            $before,
            null
        );

        return redirect()
            ->route('workspace.pao-objectifs-operationnels.index')
            ->with('success', 'Objectif operationnel supprime avec succes.');
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function validateResponsableDirection(array $validated, ?int $directionId): void
    {
        $responsableId = (int) ($validated['responsable_id'] ?? 0);
        if ($responsableId <= 0 || $directionId === null) {
            return;
        }

        $responsable = User::query()->find($responsableId);
        if ($responsable !== null
            && $responsable->direction_id !== null
            && (int) $responsable->direction_id !== $directionId
        ) {
            throw ValidationException::withMessages([
                'responsable_id' => 'Le responsable doit appartenir a la meme direction que le PAO.',
            ]);
        }
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PaoObjectifStrategique>
     */
    private function objectifStrategiqueOptions(User $user)
    {
        $query = PaoObjectifStrategique::query()
            ->with('paoAxe.pao:id,direction_id,annee,titre,statut')
            ->orderByDesc('id');

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

        return $query->get(['id', 'pao_axe_id', 'code', 'libelle']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function responsableOptions(User $user)
    {
        $query = User::query()->orderBy('name');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('service_id', (int) $user->service_id);
        }

        return $query->get(['id', 'name', 'email', 'direction_id', 'service_id']);
    }

    /**
     * @return array<int, string>
     */
    private function statusOptions(): array
    {
        return ['non_demarre', 'en_cours', 'en_retard', 'bloque', 'termine', 'annule'];
    }

    /**
     * @return array<int, string>
     */
    private function prioriteOptions(): array
    {
        return ['basse', 'moyenne', 'haute', 'critique'];
    }
}
