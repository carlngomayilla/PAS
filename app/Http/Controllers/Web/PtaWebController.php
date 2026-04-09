<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePtaRequest;
use App\Http\Requests\UpdatePtaRequest;
use App\Models\JournalAudit;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\WorkflowSettings;
use App\Support\UiLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PtaWebController extends Controller
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

        $query = Pta::query()
            ->with([
                'pao:id,pas_id,direction_id,service_id,annee,titre,statut',
                'pao.service:id,direction_id,code,libelle',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'validateur:id,name,email',
            ])
            ->withCount('actions');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        $query->when(
            $request->filled('pao_id'),
            fn ($q) => $q->where('pao_id', (int) $request->integer('pao_id'))
        );
        $query->when(
            $request->filled('direction_id'),
            fn ($q) => $q->where('direction_id', (int) $request->integer('direction_id'))
        );
        $query->when(
            $request->filled('service_id'),
            fn ($q) => $q->where('service_id', (int) $request->integer('service_id'))
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
            $request->boolean('without_action'),
            fn ($q) => $q->doesntHave('actions')
        );
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where(function ($subQuery) use ($search): void {
                $subQuery->where('titre', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        return view('workspace.pta.index', [
            'rows' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'paoOptions' => $this->paoOptions($user),
            'serviceOptions' => $this->serviceOptions($user),
            'statusOptions' => array_merge($this->statusOptions($user), ['valide_ou_verrouille']),
            'canWrite' => $this->canWrite($user),
            'filters' => [
                'q' => (string) $request->string('q'),
                'pao_id' => $request->filled('pao_id') ? (int) $request->integer('pao_id') : null,
                'direction_id' => $request->filled('direction_id') ? (int) $request->integer('direction_id') : null,
                'service_id' => $request->filled('service_id') ? (int) $request->integer('service_id') : null,
                'statut' => $statusFilter,
                'without_action' => $request->boolean('without_action'),
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

        return view('workspace.pta.form', [
            'mode' => 'create',
            'row' => tap(new Pta(), function (Pta $pta) use ($request): void {
                if ($request->filled('pao_id')) {
                    $pta->pao_id = (int) $request->integer('pao_id');
                }
            }),
            'paoOptions' => $this->paoOptions($user),
            'statusOptions' => $this->statusOptions($user),
            'timeline' => [],
        ]);
    }

    public function store(StorePtaRequest $request): RedirectResponse
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
        $serviceId = $pao->service_id !== null ? (int) $pao->service_id : null;
        if ($serviceId === null) {
            return back()->withInput()->withErrors([
                'pao_id' => 'Le PAO selectionne n est pas encore affecte a un service.',
            ]);
        }

        $statut = (string) ($validated['statut'] ?? 'brouillon');
        if (! in_array($statut, $this->statusOptions($user), true)) {
            return back()->withInput()->withErrors([
                'statut' => 'Le statut selectionne n est pas autorise pour votre profil.',
            ]);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pao->direction_id,
            $serviceId
        );

        $payload = [
            'pao_id' => (int) $pao->id,
            'direction_id' => (int) $pao->direction_id,
            'service_id' => $serviceId,
            'titre' => (string) $validated['titre'],
            'description' => $validated['description'] ?? null,
            'statut' => $statut,
        ];

        if (in_array($payload['statut'], ['valide', 'verrouille'], true)) {
            $payload['valide_le'] = now();
            $payload['valide_par'] = $user->id;
        } else {
            $payload['valide_le'] = null;
            $payload['valide_par'] = null;
        }

        $pta = Pta::query()->create($payload);

        $this->recordAudit($request, 'pta', 'create', $pta, null, $pta->toArray());

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('pta')));
    }

    public function edit(Request $request, Pta $pta): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        return view('workspace.pta.form', [
            'mode' => 'edit',
            'row' => $pta,
            'paoOptions' => $this->paoOptions($user),
            'statusOptions' => $this->statusOptions($user),
            'timeline' => $this->validationTimeline($pta),
        ]);
    }

    public function update(UpdatePtaRequest $request, Pta $pta): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PTA', 'plus etre modifie')]);
        }

        $validated = $request->validated();
        $targetPao = Pao::query()->findOrFail((int) $validated['pao_id']);
        $targetServiceId = $targetPao->service_id !== null ? (int) $targetPao->service_id : null;
        if ($targetServiceId === null) {
            return back()->withInput()->withErrors([
                'pao_id' => 'Le PAO selectionne n est pas encore affecte a un service.',
            ]);
        }

        $statut = (string) ($validated['statut'] ?? 'brouillon');
        if (! in_array($statut, $this->statusOptions($user), true)) {
            return back()->withInput()->withErrors([
                'statut' => 'Le statut selectionne n est pas autorise pour votre profil.',
            ]);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );
        $this->denyUnlessManagePta(
            $user,
            (int) $targetPao->direction_id,
            $targetServiceId
        );

        $payload = [
            'pao_id' => (int) $targetPao->id,
            'direction_id' => (int) $targetPao->direction_id,
            'service_id' => $targetServiceId,
            'titre' => (string) $validated['titre'],
            'description' => $validated['description'] ?? null,
            'statut' => $statut,
        ];

        if (in_array($payload['statut'], ['valide', 'verrouille'], true)) {
            $payload['valide_le'] = now();
            $payload['valide_par'] = $user->id;
        } else {
            $payload['valide_le'] = null;
            $payload['valide_par'] = null;
        }

        $before = $pta->toArray();
        $pta->update($payload);

        $this->recordAudit($request, 'pta', 'update', $pta, $before, $pta->toArray());

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pta')));
    }

    public function destroy(Request $request, Pta $pta): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PTA', 'etre supprime')]);
        }

        $this->denyUnlessManagePta(
            $user,
            (int) $pta->direction_id,
            (int) $pta->service_id
        );

        $before = $pta->toArray();
        $pta->delete();

        $this->recordAudit($request, 'pta', 'delete', $pta, $before, null);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pta')));
    }

    public function submit(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PTA', 'etre soumis')]);
        }

        if ($pta->statut !== 'brouillon') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PTA', 'brouillon', 'soumis')]);
        }

        $this->denyUnlessManagePta($user, (int) $pta->direction_id, (int) $pta->service_id);
        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['submit_enabled']) {
            return back()->withErrors(['general' => 'La soumission est desactivee pour le workflow PTA actif.']);
        }

        $before = $pta->toArray();
        $targetStatus = (string) $workflow['submit_target_status'];
        $pta->update([
            'statut' => $targetStatus,
            'valide_le' => in_array($targetStatus, ['valide', 'verrouille'], true) ? now() : null,
            'valide_par' => in_array($targetStatus, ['valide', 'verrouille'], true) ? $user->id : null,
        ]);

        $this->recordAudit($request, 'pta', 'submit', $pta, $before, $pta->toArray());
        $notificationService->notifyPtaStatus($pta, $targetStatus === 'soumis' ? 'submitted' : 'approved', $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $targetStatus === 'soumis'
                ? 'PTA soumis pour validation.'
                : 'PTA valide directement selon le workflow configure.');
    }

    public function approve(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user, $pta)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['approve_enabled']) {
            return back()->withErrors(['general' => 'La validation intermediaire est desactivee pour le workflow PTA actif.']);
        }

        if ($pta->statut !== 'soumis') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PTA', 'soumis', 'valide')]);
        }

        $before = $pta->toArray();
        $pta->update([
            'statut' => 'valide',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ]);

        $this->recordAudit($request, 'pta', 'approve', $pta, $before, $pta->toArray());
        $notificationService->notifyPtaStatus($pta, 'approved', $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->transitionedStateMessage('PTA', 'valide'));
    }

    public function lock(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canLock($user)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['lock_enabled']) {
            return back()->withErrors(['general' => 'Le verrouillage final est desactive pour le workflow PTA actif.']);
        }

        if ($pta->statut !== 'valide') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PTA', 'valide', 'verrouille')]);
        }

        $before = $pta->toArray();
        $pta->update([
            'statut' => 'verrouille',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ]);

        $this->recordAudit($request, 'pta', 'lock', $pta, $before, $pta->toArray());
        $notificationService->notifyPtaStatus($pta, 'locked', $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->transitionedStateMessage('PTA', 'verrouille'));
    }

    public function reopen(Request $request, Pta $pta, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pta->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedCannotBeReopenedMessage('PTA')]);
        }

        $workflow = $this->planningWorkflowSummary();
        $allowedStatuses = $workflow['reopen_allowed_statuses'];

        if (! in_array($pta->statut, $allowedStatuses, true)) {
            return back()->withErrors(['general' => $this->reopenAllowedStatusesMessage($allowedStatuses)]);
        }

        if ($pta->statut === 'soumis'
            && ! ($this->canApprove($user, $pta) || $this->canManagePta($user, (int) $pta->direction_id, (int) $pta->service_id))
        ) {
            abort(403, 'Acces non autorise.');
        }

        if ($pta->statut === 'valide'
            && $workflow['approve_enabled']
            && ! $this->canApprove($user, $pta)
        ) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validate([
            'motif_retour' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $motifRetour = trim((string) $validated['motif_retour']);

        $before = $pta->toArray();
        $pta->update([
            'statut' => 'brouillon',
            'valide_le' => null,
            'valide_par' => null,
        ]);

        $after = array_merge($pta->toArray(), ['motif_retour' => $motifRetour]);
        $this->recordAudit($request, 'pta', 'reopen', $pta, $before, $after);
        $notificationService->notifyPtaStatus($pta, 'reopened', $user);

        return redirect()
            ->route('workspace.pta.index')
            ->with('success', $this->reopenedStateMessage('PTA'));
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess()
            || $user->hasRole(User::ROLE_SERVICE);
    }

    private function canApprove(User $user, Pta $pta): bool
    {
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        return $user->hasRole(User::ROLE_DIRECTION)
            && $user->direction_id !== null
            && (int) $user->direction_id === (int) $pta->direction_id;
    }

    private function canLock(User $user): bool
    {
        return $user->hasGlobalWriteAccess();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validationTimeline(Pta $pta): array
    {
        return JournalAudit::query()
            ->with('user:id,name,email,role')
            ->where('module', 'pta')
            ->where('entite_type', $pta::class)
            ->where('entite_id', (int) $pta->id)
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Pao>
     */
    private function paoOptions(User $user)
    {
        $query = Pao::query()
            ->with([
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
            ])
            ->orderByDesc('annee')
            ->orderByDesc('id');

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');

        return $query
            ->whereNotNull('service_id')
            ->get(['id', 'direction_id', 'service_id', 'annee', 'titre']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Service>
     */
    private function serviceOptions(User $user)
    {
        $query = Service::query()
            ->with('direction:id,code,libelle')
            ->where('actif', true)
            ->orderBy('direction_id')
            ->orderBy('code');

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where('direction_id', (int) $user->direction_id);
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where('id', (int) $user->service_id);
        }

        return $query->get(['id', 'direction_id', 'code', 'libelle']);
    }

    /**
     * @return array<int, string>
     */
    private function statusOptions(User $user): array
    {
        $workflow = $this->planningWorkflowSummary();

        if ($user->hasGlobalWriteAccess()) {
            return $workflow['status_options_global'];
        }

        return $workflow['status_options_writer'];
    }

    /**
     * @return array<string, mixed>
     */
    private function planningWorkflowSummary(): array
    {
        return app(WorkflowSettings::class)->planningWorkflowSummary('pta');
    }
}
