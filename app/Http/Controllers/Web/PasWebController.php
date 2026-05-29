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
use App\Services\PlanningModificationLockService;
use App\Services\PlanningClosureReportService;
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
        if ($user->isAgent()) {
            abort(403, 'Acces non autorise.');
        }

        $query = Pas::query();

        $this->scopePasByUser($query, $user);
        app(ExerciceContext::class)->applyToPas($query);

        $statusFilter = trim((string) $request->string('statut'));
        if ($statusFilter !== '') {
            $query->where('statut', $statusFilter);
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
            'actifs'          => (int) ($byStatus[Pas::STATUS_ACTIF] ?? 0),
            'clotures'        => (int) ($byStatus[Pas::STATUS_CLOTURE] ?? 0),
            'archives'        => (int) ($byStatus[Pas::STATUS_ARCHIVE] ?? 0),
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
                    ->with(['objectifs:id,pas_axe_id,code,libelle,date_echeance,ordre,valeurs_cible']),
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
            'statusOptions' => $this->statusOptions($user),
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
                'statut' => Pas::STATUS_ACTIF,
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
        app(PlanningModificationLockService::class)->lockAfterSave($pas, $user);

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

        if ($pas->statut === Pas::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de modifier un PAS archive.']);
        }
        if ($message = app(PlanningModificationLockService::class)->ensureUnlocked($pas, $user)) {
            return back()->withErrors(['general' => $message]);
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
        app(PlanningModificationLockService::class)->lockAfterSave($pas->refresh(), $user);

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

        if ($pas->statut === Pas::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de supprimer directement un PAS archive.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $deletionRequests = app(\App\Services\DeletionRequestService::class);
        // Super Admin et DG peuvent supprimer directement avec cascade (PAOs, PTAs,
        // OOs, Actions, Axes, Objectifs strategiques). Pour les autres roles, la
        // suppression passe par le workflow de demande validee par le Super Admin.
        $canDeleteDirectly = $user->isSuperAdmin() || $user->hasRole(User::ROLE_DG);
        if (! $canDeleteDirectly) {
            $deletionRequest = $deletionRequests->requestBusinessDeletion($pas, $user, (string) $validated['motif'], 'pas');
            $this->recordAudit($request, 'pas', 'deletion_request_create', $deletionRequest, null, $deletionRequest->toArray());

            return redirect()
                ->route('workspace.pas.index')
                ->with('success', 'Demande de suppression PAS transmise au Super Admin.');
        }

        $before = $pas->toArray();
        $impact = $deletionRequests->impactForEntity($pas);
        $deletionRequests->deleteBusinessTarget($pas);

        $this->recordAudit($request, 'pas', 'delete', $pas, [
            ...$before,
            'deletion_reason' => (string) $validated['motif'],
            'impact' => $impact,
        ], null);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pas')));
    }

    public function close(Request $request, Pas $pas, PlanningClosureReportService $closureReportService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        if ((string) $pas->statut === Pas::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de cloturer un PAS archive.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
            'force_close' => ['nullable', 'boolean'],
        ]);

        $report = $closureReportService->forPas($pas);
        $forceClose = $request->boolean('force_close');

        if ($closureReportService->hasAnomalies($report) && ! $forceClose) {
            return back()
                ->withInput()
                ->with('closure_report', $report)
                ->withErrors(['general' => $this->closureReportErrorMessage($report)]);
        }

        $before = $pas->toArray();
        $pas->forceFill([
            'statut' => Pas::STATUS_CLOTURE,
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pas', 'close', $pas, $before, [
            ...$pas->toArray(),
            'motif' => (string) $validated['motif'],
            'closure_report' => $report,
            'forced_with_anomalies' => $forceClose,
        ]);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', 'PAS cloture avec rapport d anomalies trace.');
    }

    public function archive(Request $request, Pas $pas): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessStrategicWriter($user);

        if ((string) $pas->statut !== Pas::STATUS_CLOTURE) {
            return back()->withErrors(['general' => 'Le PAS doit etre cloture avant archivage.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $before = $pas->toArray();
        $pas->forceFill([
            'statut' => Pas::STATUS_ARCHIVE,
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pas', 'archive', $pas, $before, [
            ...$pas->toArray(),
            'motif' => (string) $validated['motif'],
        ]);

        return redirect()
            ->route('workspace.pas.index')
            ->with('success', 'PAS archive.');
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
     * @param array<string, mixed> $report
     */
    private function closureReportErrorMessage(array $report): string
    {
        $labels = collect($report['issues'] ?? [])
            ->map(fn (array $issue): string => $issue['label'].' ('.$issue['count'].')')
            ->implode(', ');

        return 'Rapport d anomalies obligatoire : '.$labels.'. Ajoutez une justification et cochez la cloture avec anomalies pour continuer.';
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
                    'id' => (int) $axe->id,
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
                                'id' => (int) $objectif->id,
                                'code' => (string) $objectif->code,
                                'libelle' => (string) $objectif->libelle,
                                'date_echeance' => optional($objectif->date_echeance)?->format('Y-m-d'),
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
            ->whereIn('action', ['create', 'update', 'close', 'archive'])
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
                    'close' => 'Cloture',
                    'archive' => 'Archivage',
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
        $title = trim((string) ($validated['titre'] ?? ''));
        if ($title !== '') {
            return $title;
        }

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
