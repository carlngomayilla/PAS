<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Concerns\FormatsWorkflowMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasRequest;
use App\Http\Requests\UpdatePasRequest;
use App\Models\Direction;
use App\Models\JournalAudit;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\User;
use App\Services\PasStructureService;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\ExerciceContext;
use App\Services\WorkflowSettings;
use App\Support\UiLabel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    public function __construct(
        private readonly PasStructureService $pasStructureService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessPlanningReader($user);

        $query = Pas::query();

        $this->scopePasByUser($query, $user);
        app(ExerciceContext::class)->applyToPas($query);

        $statusFilter = trim((string) $request->string('statut'));
        if ($statusFilter !== '') {
            if ($statusFilter === 'valide_ou_verrouille') {
                $query->whereIn('statut', ['valide', 'verrouille']);
            } else {
                $query->where('statut', $statusFilter);
            }
        }
        $query->when(
            $request->boolean('without_pao'),
            fn ($q) => $q->doesntHave('paos')
        );

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $search = trim((string) $request->string('q'));
            $q->where('titre', 'like', "%{$search}%");
        });

        $statsBase = clone $query;
        $byStatus = (clone $statsBase)
            ->select('statut')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('statut')
            ->pluck('cnt', 'statut');
        $pasIdsSubquery = (clone $statsBase)->select('id');
        $pasStats = [
            'total'           => (int) $byStatus->sum(),
            'actifs'          => (int) (($byStatus['valide'] ?? 0) + ($byStatus['verrouille'] ?? 0)),
            'brouillons'      => (int) ($byStatus['brouillon'] ?? 0),
            'sans_pao'        => (clone $statsBase)->doesntHave('paos')->count(),
            'sans_axe'        => (clone $statsBase)->doesntHave('axes')->count(),
            'axes_total'      => PasAxe::query()->whereIn('pas_id', $pasIdsSubquery)->count(),
            'objectifs_total' => PasObjectif::query()->whereIn(
                'pas_axe_id',
                PasAxe::query()->whereIn('pas_id', $pasIdsSubquery)->select('id')
            )->count(),
        ];

        $rows = $query
            ->with([
                'validateur:id,name,email',
                'axes' => fn ($subQuery) => $subQuery
                    ->select(['id', 'pas_id', 'code', 'libelle', 'periode_debut', 'periode_fin', 'ordre'])
                    ->orderBy('ordre')
                    ->orderBy('id')
                    ->with(['objectifs:id,pas_axe_id,code,libelle,ordre,valeurs_cible']),
            ])
            ->withCount(['axes', 'paos'])
            ->orderByDesc('periode_debut')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('workspace.pas.index', [
            'rows' => $rows,
            'pasStats' => $pasStats,
            'canWrite' => $this->canWrite($user),
            'statusOptions' => array_merge($this->statusOptions($user), ['valide_ou_verrouille']),
            'filters' => [
                'q' => (string) $request->string('q'),
                'statut' => $statusFilter,
                'without_pao' => $request->boolean('without_pao'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        return view('workspace.pas.form', [
            'mode' => 'create',
            'row' => new Pas(),
            'statusOptions' => $this->statusOptions($user),
            'directionOptions' => $this->directionOptions(),
            'axesPayload' => [],
            'timeline' => [],
        ]);
    }

    public function store(StorePasRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        $validated = $request->validated();

        $payload = [
            'titre' => $this->generatedPasTitle($validated),
            'periode_debut' => (int) $validated['periode_debut'],
            'periode_fin' => (int) $validated['periode_fin'],
            'created_by' => $user->id,
        ];

        $pas = DB::transaction(function () use ($validated, $payload, $user): Pas {
            $pas = new Pas();
            $pas->fill($payload);
            $pas->forceFill([
                'statut' => 'brouillon',
                'valide_le' => null,
                'valide_par' => null,
            ])->save();
            $this->pasStructureService->sync(
                $pas,
                is_array($validated['axes'] ?? null) ? $validated['axes'] : [],
                $user->id,
            );

            return $pas;
        });

        $after = $pas->load([
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query->with('objectifs')->orderBy('ordre')->orderBy('id'),
        ])->toArray();
        $this->recordAudit($request, 'pas', 'create', $pas, null, $after);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('pas')));
    }

    public function edit(Request $request, Pas $pas): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        return view('workspace.pas.form', [
            'mode' => 'edit',
            'row' => $pas,
            'statusOptions' => $this->statusOptions($user),
            'directionOptions' => $this->directionOptions(),
            'axesPayload' => $this->axesPayload($pas),
            'timeline' => $this->validationTimeline($pas),
        ]);
    }

    public function update(UpdatePasRequest $request, Pas $pas): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        if ($pas->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PAS', 'plus etre modifie')]);
        }

        $validated = $request->validated();

        $payload = [
            'titre' => $this->generatedPasTitle($validated),
            'periode_debut' => (int) $validated['periode_debut'],
            'periode_fin' => (int) $validated['periode_fin'],
        ];

        $before = $pas->load([
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query->with('objectifs')->orderBy('ordre')->orderBy('id'),
        ])->toArray();

        DB::transaction(function () use ($pas, $validated, $payload, $user): void {
            $pas->update($payload);
            $this->pasStructureService->sync(
                $pas,
                is_array($validated['axes'] ?? null) ? $validated['axes'] : [],
                $user->id,
            );
        });

        $pas->refresh();
        $after = $pas->load([
            'directions:id,code,libelle',
            'axes' => fn ($query) => $query->with('objectifs')->orderBy('ordre')->orderBy('id'),
        ])->toArray();

        $this->recordAudit($request, 'pas', 'update', $pas, $before, $after);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pas')));
    }

    public function destroy(Request $request, Pas $pas): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        if ($pas->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PAS', 'etre supprime')]);
        }

        $before = $pas->toArray();
        $pas->delete();

        $this->recordAudit($request, 'pas', 'delete', $pas, $before, null);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pas')));
    }

    public function submit(Request $request, Pas $pas, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        if ($pas->statut === 'verrouille') {
            return back()->withErrors(['general' => $this->lockedStateMessage('PAS', 'etre soumis')]);
        }

        if ($pas->statut !== 'brouillon') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PAS', 'brouillon', 'soumis')]);
        }

        $before = $pas->toArray();
        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['submit_enabled']) {
            return back()->withErrors(['general' => 'La soumission est desactivee pour le workflow PAS actif.']);
        }

        $targetStatus = (string) $workflow['submit_target_status'];
        $pas->forceFill([
            'statut' => $targetStatus,
            'valide_le' => in_array($targetStatus, ['valide', 'verrouille'], true) ? now() : null,
            'valide_par' => in_array($targetStatus, ['valide', 'verrouille'], true) ? $user->id : null,
        ])->save();

        $this->recordAudit($request, 'pas', 'submit', $pas, $before, $pas->toArray());
        $notificationService->notifyPasStatus($pas, $targetStatus === 'soumis' ? 'submitted' : 'approved', $user);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $targetStatus === 'soumis'
                ? 'PAS soumis pour validation.'
                : 'PAS valide directement selon le workflow configure.');
    }

    public function approve(Request $request, Pas $pas, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['approve_enabled']) {
            return back()->withErrors(['general' => 'La validation intermediaire est desactivee pour le workflow PAS actif.']);
        }

        if ($pas->statut !== 'soumis') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PAS', 'soumis', 'valide')]);
        }

        $before = $pas->toArray();
        $pas->forceFill([
            'statut' => 'valide',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pas', 'approve', $pas, $before, $pas->toArray());
        $notificationService->notifyPasStatus($pas, 'approved', $user);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->transitionedStateMessage('PAS', 'valide'));
    }

    public function lock(Request $request, Pas $pas, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        if (! $workflow['lock_enabled']) {
            return back()->withErrors(['general' => 'Le verrouillage final est desactive pour le workflow PAS actif.']);
        }

        if ($pas->statut !== 'valide') {
            return back()->withErrors(['general' => $this->requiredStateMessage('PAS', 'valide', 'verrouille')]);
        }

        $before = $pas->toArray();
        $pas->forceFill([
            'statut' => 'verrouille',
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pas', 'lock', $pas, $before, $pas->toArray());
        $notificationService->notifyPasStatus($pas, 'locked', $user);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->transitionedStateMessage('PAS', 'verrouille'));
    }

    public function reopen(Request $request, Pas $pas, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canApprove($user)) {
            abort(403, 'Acces non autorise.');
        }

        $workflow = $this->planningWorkflowSummary();
        $allowedStatuses = $workflow['reopen_allowed_statuses'];

        if (! in_array($pas->statut, $allowedStatuses, true)) {
            return back()->withErrors(['general' => $this->reopenAllowedStatusesMessage($allowedStatuses)]);
        }

        $validated = $request->validate([
            'motif_retour' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $motifRetour = trim((string) $validated['motif_retour']);

        $before = $pas->toArray();
        $pas->forceFill([
            'statut' => 'brouillon',
            'valide_le' => null,
            'valide_par' => null,
        ])->save();

        $after = array_merge($pas->toArray(), ['motif_retour' => $motifRetour]);
        $this->recordAudit($request, 'pas', 'reopen', $pas, $before, $after);
        $notificationService->notifyPasStatus($pas, 'reopened', $user);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->reopenedStateMessage('PAS'));
    }

    private function canWrite(User $user): bool
    {
        return $this->canWriteStrategicPlanning($user);
    }

    private function canApprove(User $user): bool
    {
        // A06 — DG est en lecture seule pure : il consulte le PAS mais ne le
        // valide / verrouille pas. La validation finale revient aux profils
        // SUPER_ADMIN et ADMIN (operations) uniquement.
        return $user->hasRole(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_ADMIN_FONCTIONNEL);
    }

    /**
     * @return EloquentCollection<int, Direction>
     */
    private function directionOptions(?User $user = null): EloquentCollection
    {
        $query = Direction::query()
            ->where('actif', true)
            ->orderBy('code');

        if ($user instanceof User && ! $user->hasGlobalReadAccess()) {
            if ($user->direction_id !== null && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
                $query->whereKey((int) $user->direction_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->get(['id', 'code', 'libelle']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function axesPayload(Pas $pas): array
    {
        $pas->load([
            'axes' => fn ($query) => $query->with('objectifs')->orderBy('ordre')->orderBy('id'),
        ]);

        return $pas->axes
            ->map(function ($axe): array {
                return [
                    'direction_id' => $axe->direction_id !== null ? (int) $axe->direction_id : null,
                    'code' => (string) $axe->code,
                    'libelle' => (string) $axe->libelle,
                    'periode_debut' => optional($axe->periode_debut)?->format('Y-m-d'),
                    'periode_fin' => optional($axe->periode_fin)?->format('Y-m-d'),
                    'description' => $axe->description,
                    'ordre' => (int) $axe->ordre,
                    'objectifs' => $axe->objectifs
                        ->map(function ($objectif): array {
                            return [
                                'code' => (string) $objectif->code,
                                'libelle' => (string) $objectif->libelle,
                                'description' => $objectif->description,
                                'ordre' => (int) $objectif->ordre,
                                'indicateur_global' => $objectif->indicateur_global,
                                'valeur_cible' => $objectif->valeur_cible,
                                'valeurs_cible' => $objectif->valeurs_cible,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validationTimeline(Pas $pas): array
    {
        return JournalAudit::query()
            ->with('user:id,name,email,role')
            ->where('module', 'pas')
            ->where('entite_type', $pas::class)
            ->where('entite_id', (int) $pas->id)
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
     * @param array<string, mixed> $validated
     */
    private function generatedPasTitle(array $validated): string
    {
        $start = (int) ($validated['periode_debut'] ?? 0);
        $end = (int) ($validated['periode_fin'] ?? 0);

        if ($start > 0 && $end > 0 && $start !== $end) {
            return 'PAS '.$start.'-'.$end;
        }

        if ($start > 0) {
            return 'PAS '.$start;
        }

        return 'PAS';
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
        return app(WorkflowSettings::class)->planningWorkflowSummary('pas');
    }
}
