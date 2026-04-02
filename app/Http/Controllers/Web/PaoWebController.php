<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaoRequest;
use App\Http\Requests\UpdatePaoRequest;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasObjectif;
use App\Models\Service;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaoWebController extends Controller
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

        $query = Pao::query()
            ->with([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ])
            ->withCount(['ptas']);

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        $query->when(
            $request->filled('pas_id'),
            fn ($q) => $q->whereHas(
                'pasObjectif.pasAxe',
                fn ($subQuery) => $subQuery->where('pas_id', (int) $request->integer('pas_id'))
            )
        );
        $query->when(
            $request->filled('pas_objectif_id'),
            fn ($q) => $q->where('pas_objectif_id', (int) $request->integer('pas_objectif_id'))
        );
        $query->when(
            $request->filled('direction_id'),
            fn ($q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('service_id'),
            fn ($q) => $q->where('service_id', (int) $request->integer('service_id'))
        );
        $query->when(
            $request->filled('annee'),
            fn ($q) => $q->where('annee', (int) $request->integer('annee'))
        );
        $statusFilter = trim((string) $request->string('statut'));
        if ($statusFilter !== '') {
            if ($statusFilter === 'valide_ou_verrouille') {
                $query->whereIn('statut', ['valide', 'verrouille']);
            } else {
                $query->where('statut', $statusFilter);
            }
        }
        $query->when(
            $request->boolean('without_pta'),
            fn ($q) => $q->doesntHave('ptas')
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('titre', 'like', "%{$search}%")
                    ->orWhere('objectif_operationnel', 'like', "%{$search}%")
                    ->orWhere('resultats_attendus', 'like', "%{$search}%")
                    ->orWhere('indicateurs_associes', 'like', "%{$search}%")
                    ->orWhereHas('pasObjectif', fn ($objectifQuery) => $objectifQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('libelle', 'like', "%{$search}%"))
                    ->orWhereHas('pasObjectif.pasAxe', fn ($axeQuery) => $axeQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('libelle', 'like', "%{$search}%"));
            });
        });

        return view('workspace.pao.index', [
            'rows' => $query->orderByDesc('annee')->orderByDesc('id')->paginate(15)->withQueryString(),
            'pasOptions' => $this->pasOptions($user),
            'objectifOptions' => $this->objectifOptions($user),
            'directionOptions' => $this->directionOptions($user),
            'serviceOptions' => $this->serviceOptions($user),
            'statusOptions' => array_merge($this->statusOptions($user), ['valide_ou_verrouille']),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pas_id' => $request->filled('pas_id') ? (int) $request->integer('pas_id') : null,
                'pas_objectif_id' => $request->filled('pas_objectif_id') ? (int) $request->integer('pas_objectif_id') : null,
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'annee' => $request->filled('annee') ? (int) $request->integer('annee') : null,
                'statut' => $statusFilter,
                'without_pta' => $request->boolean('without_pta'),
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

        $directionOptions = $this->directionOptions($user);
        $prefilledObjectifId = $request->filled('pas_objectif_id') ? (int) $request->integer('pas_objectif_id') : null;
        $prefilledDirectionId = $request->filled('direction_id') ? (int) $request->integer('direction_id') : null;
        $prefilledServiceId = $request->filled('service_id') ? (int) $request->integer('service_id') : null;
        $objectifOptions = $this->objectifOptions($user, $prefilledObjectifId);
        $row = new Pao();
        $row->pas_objectif_id = $prefilledObjectifId;
        $row->direction_id = $prefilledDirectionId;
        $row->service_id = $prefilledServiceId;
        $row->annee = $request->filled('annee') ? (int) $request->integer('annee') : null;

        return view('workspace.pao.form', [
            'mode' => 'create',
            'row' => $row,
            'pasOptions' => $this->pasOptions($user),
            'objectifOptions' => $objectifOptions,
            'directionOptions' => $directionOptions,
            'serviceOptions' => $this->serviceOptions($user, $prefilledDirectionId, $prefilledServiceId),
            'objectifMap' => $this->objectifMap($objectifOptions),
            'statusOptions' => $this->statusOptions($user),
            'timeline' => [],
        ]);
    }

    public function store(StorePaoRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $statut = (string) ($validated['statut'] ?? 'brouillon');
        if (! in_array($statut, $this->statusOptions($user), true)) {
            return back()->withInput()->withErrors([
                'statut' => 'Le statut selectionne n est pas autorise pour votre profil.',
            ]);
        }

        $this->denyUnlessManagePao($user, (int) $validated['direction_id']);

        $objectif = $this->resolveAccessibleObjectif($user, (int) $validated['pas_objectif_id']);

        $payload = [
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => (int) $validated['service_id'],
            'annee' => (int) $validated['annee'],
            'titre' => (string) $validated['titre'],
            'echeance' => $validated['echeance'] ?? null,
            'objectif_operationnel' => $validated['objectif_operationnel'] ?? null,
            'resultats_attendus' => $validated['resultats_attendus'] ?? null,
            'indicateurs_associes' => $validated['indicateurs_associes'] ?? null,
            'statut' => $statut,
        ];

        if (in_array($payload['statut'], ['valide', 'verrouille'], true)) {
            $payload['valide_le'] = now();
            $payload['valide_par'] = $user->id;
        } else {
            $payload['valide_le'] = null;
            $payload['valide_par'] = null;
        }

        $pao = Pao::query()->create($payload);

        $this->recordAudit($request, 'pao', 'create', $pao, null, $pao->toArray());

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('pao')));
    }

    public function edit(Request $request, Pao $pao): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);

        $pasOptions = $this->pasOptions($user, (int) $pao->pas_id);
        $objectifOptions = $this->objectifOptions($user, (int) $pao->pas_objectif_id);
        $directionOptions = $this->directionOptions($user);

        return view('workspace.pao.form', [
            'mode' => 'edit',
            'row' => $pao,
            'pasOptions' => $pasOptions,
            'objectifOptions' => $objectifOptions,
            'directionOptions' => $directionOptions,
            'serviceOptions' => $this->serviceOptions($user, (int) $pao->direction_id, (int) $pao->service_id),
            'objectifMap' => $this->objectifMap($objectifOptions),
            'statusOptions' => $this->statusOptions($user),
            'timeline' => $this->validationTimeline($pao),
        ]);
    }

    public function update(UpdatePaoRequest $request, Pao $pao): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PAO', 'plus etre modifie')]);
        }

        $validated = $request->validated();
        $statut = (string) ($validated['statut'] ?? 'brouillon');
        if (! in_array($statut, $this->statusOptions($user), true)) {
            return back()->withInput()->withErrors([
                'statut' => 'Le statut selectionne n est pas autorise pour votre profil.',
            ]);
        }

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);
        $this->denyUnlessManagePao($user, (int) $validated['direction_id']);
        $objectif = $this->resolveAccessibleObjectif($user, (int) $validated['pas_objectif_id']);

        if ($pao->ptas()->exists() && (int) $pao->service_id !== (int) $validated['service_id']) {
            return back()->withInput()->withErrors([
                'service_id' => 'Le service d un PAO deja decliné en PTA ne peut plus etre modifie.',
            ]);
        }

        $payload = [
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => (int) $validated['service_id'],
            'annee' => (int) $validated['annee'],
            'titre' => (string) $validated['titre'],
            'echeance' => $validated['echeance'] ?? null,
            'objectif_operationnel' => $validated['objectif_operationnel'] ?? null,
            'resultats_attendus' => $validated['resultats_attendus'] ?? null,
            'indicateurs_associes' => $validated['indicateurs_associes'] ?? null,
            'statut' => $statut,
        ];

        if (in_array($payload['statut'], ['valide', 'verrouille'], true)) {
            $payload['valide_le'] = now();
            $payload['valide_par'] = $user->id;
        } else {
            $payload['valide_le'] = null;
            $payload['valide_par'] = null;
        }

        $before = $pao->toArray();
        $pao->update($payload);

        $this->recordAudit($request, 'pao', 'update', $pao, $before, $pao->toArray());

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pao')));
    }

    public function destroy(Request $request, Pao $pao): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PAO', 'etre supprime')]);
        }

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);

        $before = $pao->toArray();
        $pao->delete();

        $this->recordAudit($request, 'pao', 'delete', $pao, $before, null);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pao')));
    }

    public function submit(Request $request, Pao $pao, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PAO', 'etre soumis')]);
        }

        if ($pao->statut !== 'brouillon') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PAO', 'brouillon', 'soumis')]);
        }

        $this->denyUnlessWriteDirection($user, (int) $pao->direction_id);

        $before = $pao->toArray();
        $pao->update([
            'statut' => 'soumis',
            'valide_le' => null,
            'valide_par' => null,
        ]);

        $this->recordAudit($request, 'pao', 'submit', $pao, $before, $pao->toArray());
        $notificationService->notifyPaoStatus($pao, 'submitted', $user);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', 'PAO soumis pour validation.');
    }

    public function approve(Request $request, Pao $pao, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user)) {
            abort(403, 'Acces non autorise.');
        }

        if ($pao->statut !== 'soumis') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PAO', 'soumis', 'valide')]);
        }

        $before = $pao->toArray();
        $pao->update([
            'statut' => 'valide',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ]);

        $this->recordAudit($request, 'pao', 'approve', $pao, $before, $pao->toArray());
        $notificationService->notifyPaoStatus($pao, 'approved', $user);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->transitionedStateMessage('PAO', 'valide'));
    }

    public function lock(Request $request, Pao $pao, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user)) {
            abort(403, 'Acces non autorise.');
        }

        if ($pao->statut !== 'valide') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PAO', 'valide', 'verrouille')]);
        }

        $before = $pao->toArray();
        $pao->update([
            'statut' => 'verrouille',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ]);

        $this->recordAudit($request, 'pao', 'lock', $pao, $before, $pao->toArray());
        $notificationService->notifyPaoStatus($pao, 'locked', $user);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->transitionedStateMessage('PAO', 'verrouille'));
    }

    public function reopen(Request $request, Pao $pao, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedCannotBeReopenedMessage('PAO')]);
        }

        if (! in_array($pao->statut, ['soumis', 'valide'], true)) {
            return back()->withErrors(['general' => $this->reopenAllowedStatusesMessage(['soumis', 'valide'])]);
        }

        if ($pao->statut === 'valide' && ! $this->canApprove($user)) {
            abort(403, 'Acces non autorise.');
        }

        if ($pao->statut === 'soumis'
            && ! ($this->canApprove($user) || $this->canWriteDirection($user, (int) $pao->direction_id))
        ) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'motif_retour' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $motifRetour = trim((string) $validated['motif_retour']);

        $before = $pao->toArray();
        $pao->update([
            'statut' => 'brouillon',
            'valide_le' => null,
            'valide_par' => null,
        ]);

        $after = array_merge($pao->toArray(), ['motif_retour' => $motifRetour]);
        $this->recordAudit($request, 'pao', 'reopen', $pao, $before, $after);
        $notificationService->notifyPaoStatus($pao, 'reopened', $user);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->reopenedStateMessage('PAO'));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
    }

    private function canApprove(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_DG);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validationTimeline(Pao $pao): array
    {
        return JournalAudit::query()
            ->with('user:id,name,email,role')
            ->where('module', 'pao')
            ->where('entite_type', $pao::class)
            ->where('entite_id', (int) $pao->id)
            ->whereIn('action', ['create', 'submit', 'approve', 'lock', 'reopen', 'update'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (JournalAudit $log): bool {
                if ($log->action !== 'update') {
                    return true;
                }

                $before = is_array($log->ancienne_valeur) ? $log->ancienne_valeur : [];
                $after = is_array($log->nouvelle_valeur) ? $log->nouvelle_valeur : [];

                return ($before['statut'] ?? null) !== ($after['statut'] ?? null);
            })
            ->map(function (JournalAudit $log): array {
                $before = is_array($log->ancienne_valeur) ? $log->ancienne_valeur : [];
                $after = is_array($log->nouvelle_valeur) ? $log->nouvelle_valeur : [];
                $from = isset($before['statut']) ? (string) $before['statut'] : null;
                $to = isset($after['statut']) ? (string) $after['statut'] : null;

                $label = match ($log->action) {
                    'create' => 'Creation',
                    'submit' => 'Soumission',
                    'approve' => 'Validation',
                    'lock' => 'Verrouillage',
                    'reopen' => 'Retour brouillon',
                    'update' => 'Changement statut',
                    default => ucfirst((string) $log->action),
                };

                return [
                    'date' => $log->created_at?->format('Y-m-d H:i:s'),
                    'action' => $label,
                    'from' => $from,
                    'to' => $to,
                    'reason' => isset($after['motif_retour']) ? (string) $after['motif_retour'] : null,
                    'user' => $log->user?->name ?? 'Systeme',
                    'user_role' => $log->user?->role ?? '-',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Direction>
     */
    private function directionOptions(User $user)
    {
        $query = Direction::query()->where('actif', true)->orderBy('code');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('id', (int) $user->direction_id);
        }

        return $query->get(['id', 'code', 'libelle']);
    }

    /**
     * @return EloquentCollection<int, Service>
     */
    private function serviceOptions(User $user, ?int $directionId = null, ?int $forceServiceId = null): EloquentCollection
    {
        $query = Service::query()
            ->where('actif', true)
            ->orderBy('direction_id')
            ->orderBy('code');

        $query->where(function ($scopedQuery) use ($user, $directionId, $forceServiceId): void {
            if ($forceServiceId !== null) {
                $scopedQuery->orWhere('id', $forceServiceId);
            }

            $appliedScope = false;

            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $scopedQuery->orWhere('id', (int) $user->service_id);
                $appliedScope = true;
            }

            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $scopedQuery->orWhere('direction_id', (int) $user->direction_id);
                $appliedScope = true;
            }

            if (! $appliedScope) {
                if ($directionId !== null) {
                    $scopedQuery->orWhere('direction_id', $directionId);
                } else {
                    $scopedQuery->orWhereRaw('1 = 1');
                }
            }
        });

        return $query->get(['id', 'direction_id', 'code', 'libelle']);
    }

    /**
     * @return array<int, string>
     */
    private function statusOptions(User $user): array
    {
        if ($user->hasGlobalWriteAccess()) {
            return ['brouillon', 'soumis', 'valide', 'verrouille'];
        }

        return ['brouillon', 'soumis'];
    }

    /**
     * @return EloquentCollection<int, Pas>
     */
    private function pasOptions(User $user, ?int $forcePasId = null): EloquentCollection
    {
        $query = Pas::query()
            ->with(['directions:id,code,libelle'])
            ->orderByDesc('periode_debut')
            ->orderByDesc('id');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $directionId = (int) $user->direction_id;
            $query->where(function ($q) use ($directionId, $forcePasId): void {
                $q->whereHas('directions', fn ($subQuery) => $subQuery->whereKey($directionId));

                if ($forcePasId !== null) {
                    $q->orWhere('id', $forcePasId);
                }
            });
        }

        return $query->get(['id', 'titre', 'periode_debut', 'periode_fin']);
    }

    /**
     * @return EloquentCollection<int, PasObjectif>
     */
    private function objectifOptions(User $user, ?int $forceObjectifId = null): EloquentCollection
    {
        $query = PasObjectif::query()
            ->with([
                'pasAxe:id,pas_id,code,libelle,ordre',
                'pasAxe.pas:id,titre,periode_debut,periode_fin',
            ])
            ->orderBy('pas_axe_id')
            ->orderBy('ordre')
            ->orderBy('id');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $directionId = (int) $user->direction_id;
            $query->where(function ($subQuery) use ($directionId, $forceObjectifId): void {
                $subQuery->whereHas('pasAxe.pas.directions', fn ($pasQuery) => $pasQuery->whereKey($directionId));

                if ($forceObjectifId !== null) {
                    $subQuery->orWhereKey($forceObjectifId);
                }
            });
        }

        return $query->get(['id', 'pas_axe_id', 'code', 'libelle', 'ordre']);
    }

    /**
     * @param EloquentCollection<int, PasObjectif> $objectifOptions
     * @return array<int, array<string, mixed>>
     */
    private function objectifMap(EloquentCollection $objectifOptions): array
    {
        return $objectifOptions
            ->mapWithKeys(fn (PasObjectif $objectif): array => [
                (int) $objectif->id => [
                    'id' => (int) $objectif->id,
                    'code' => (string) $objectif->code,
                    'libelle' => (string) $objectif->libelle,
                    'axe' => (string) ($objectif->pasAxe?->code ? $objectif->pasAxe->code.' - '.$objectif->pasAxe->libelle : $objectif->pasAxe?->libelle),
                    'pas_id' => (int) ($objectif->pasAxe?->pas_id ?? 0),
                    'pas' => (string) ($objectif->pasAxe?->pas?->titre ?? '-'),
                    'periode' => $objectif->pasAxe?->pas
                        ? (string) $objectif->pasAxe->pas->periode_debut.'-'.$objectif->pasAxe->pas->periode_fin
                        : '-',
                ],
            ])
            ->all();
    }

    private function resolveAccessibleObjectif(User $user, int $objectifId): PasObjectif
    {
        $objectif = PasObjectif::query()
            ->with('pasAxe.pas.directions:id')
            ->findOrFail($objectifId);

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $allowed = $objectif->pasAxe?->pas?->directions
                ?->contains(static fn (Direction $direction): bool => (int) $direction->id === (int) $user->direction_id);

            if (! $allowed) {
                abort(403, 'Objectif strategique hors perimetre.');
            }
        }

        return $objectif;
    }
}
