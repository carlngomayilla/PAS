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
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasObjectif;
use App\Models\Service;
use App\Models\User;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\PlanningClosureReportService;
use App\Services\WorkflowSettings;
use App\Support\UiLabel;
use App\Services\ExerciceContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Contrôleur des PAO — Plan d'Actions Opérationnel.
 *
 * Un PAO est la déclinaison annuelle du PAS pour une direction. Il est rattaché à un
 * objectif stratégique du PAS et contient les PTA des services de cette direction.
 * Workflow : en cours -> valide automatiquement -> cloture -> archive.
 */
class PaoWebController extends Controller
{
    use AuthorizesPlanningScope;
    use FormatsWorkflowMessages;
    use RecordsAuditTrail;

    /** Affiche la liste des PAO avec filtres (direction, PAS, année, statut) et statistiques. */
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

        $query = Pao::query();

        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        app(ExerciceContext::class)->applyToPao(
            $query,
            $request->filled('annee') ? (int) $request->integer('annee') : null
        );

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
            fn ($q) => $q->where(function ($serviceQuery) use ($request): void {
                $serviceId = (int) $request->integer('service_id');
                $serviceQuery->where('service_id', $serviceId)
                    ->orWhereHas('objectifsOperationnels', fn ($objectiveQuery) => $objectiveQuery->where('service_id', $serviceId));
            })
        );
        $query->when(
            $request->filled('annee'),
            fn ($q) => $q->where('annee', (int) $request->integer('annee'))
        );
        $statusFilter = trim((string) $request->string('statut'));
        if ($statusFilter !== '') {
            $query->where('statut', $statusFilter);
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
                    ->orWhereHas('objectifsOperationnels', fn ($objectiveQuery) => $objectiveQuery
                        ->where('libelle', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('indicateurs', 'like', "%{$search}%"))
                    ->orWhereHas('pasObjectif', fn ($objectifQuery) => $objectifQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('libelle', 'like', "%{$search}%"))
                    ->orWhereHas('pasObjectif.pasAxe', fn ($axeQuery) => $axeQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('libelle', 'like', "%{$search}%"));
            });
        });

        $statsBase = clone $query;
        $byStatus = (clone $statsBase)
            ->select('statut')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('statut')
            ->pluck('cnt', 'statut');
        $paoStats = [
            'total'      => (int) $byStatus->sum(),
            'en_cours'   => (int) ($byStatus[Pao::STATUS_EN_COURS] ?? 0),
            'valides'    => (int) ($byStatus[Pao::STATUS_VALIDE] ?? 0),
            'clotures'   => (int) ($byStatus[Pao::STATUS_CLOTURE] ?? 0),
            'archives'   => (int) ($byStatus[Pao::STATUS_ARCHIVE] ?? 0),
            'avec_pta'   => (clone $statsBase)->has('ptas')->count(),
            'sans_pta'   => (clone $statsBase)->doesntHave('ptas')->count(),
            'directions' => (clone $statsBase)->distinct()->count('direction_id'),
        ];

        $rows = $query
            ->with([
                'pas:id,titre,periode_debut,periode_fin,statut',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'direction:id,code,libelle',
                'service:id,direction_id,code,libelle',
                'objectifsOperationnels:id,pao_id,service_id,libelle,echeance,statut',
                'validateur:id,name,email',
            ])
            ->withCount(['ptas', 'objectifsOperationnels'])
            ->orderByDesc('annee')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('workspace.pao.index', [
            'rows' => $rows,
            'scope' => $user->accessScope(),
            'paoStats' => $paoStats,
            'pasOptions' => $this->pasOptions($user),
            'objectifOptions' => $this->objectifOptions($user),
            'directionOptions' => $this->directionOptions($user),
            'serviceOptions' => $this->serviceOptions($user),
            'statusOptions' => $this->statusOptions($user),
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

    /** Affiche le formulaire de création d'un nouveau PAO. */
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
        $row->annee = $request->filled('annee') ? (int) $request->integer('annee') : app(ExerciceContext::class)->selectedYear();

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

    /** Enregistre un nouveau PAO après validation du formulaire. */
    public function store(StorePaoRequest $request, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canWrite($user)) {
            abort(403, 'Acces non autorise.');
        }

        $validated = $request->validated();
        $statut = Pao::STATUS_VALIDE;

        $this->denyUnlessManagePao($user, (int) $validated['direction_id']);

        $objectif = $this->resolveAccessibleObjectif($user, (int) $validated['pas_objectif_id']);

        $pao = DB::transaction(function () use ($validated, $objectif, $statut, $user, $request): Pao {
            $operationalObjectives = $this->validatedOperationalObjectives($validated);
            $payload = $this->paoPayload($objectif, $validated, $operationalObjectives[0], $statut, $user);
            $pao = new Pao();
            $pao->fill($payload);
            $pao->forceFill(['statut' => $statut])->save();
            $this->recordAudit($request, 'pao', 'create', $pao, null, $pao->toArray());

            foreach ($operationalObjectives as $operationalObjective) {
                $objective = $pao->objectifsOperationnels()->create(
                    $this->operationalObjectivePayload($pao, $objectif, $validated, $operationalObjective, $statut)
                );
                $this->recordAudit($request, 'objectif_operationnel', 'create', $objective, null, $objective->toArray());
            }

            return $pao;
        });

        $notificationService->notifyPaoTransmittedToServices($pao, $user);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->entityCreatedMessage(UiLabel::object('pao')).' '.count($this->validatedOperationalObjectives($validated)).' objectif(s) operationnel(s) rattache(s).');
    }

    /** Affiche le formulaire de modification d'un PAO existant. */
    public function edit(Request $request, Pao $pao): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $pao->loadMissing('objectifsOperationnels');
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

    /** Sauvegarde les modifications apportées à un PAO. */
    public function update(UpdatePaoRequest $request, Pao $pao, WorkspaceNotificationService $notificationService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === Pao::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de modifier un PAO archivé.']);
        }

        $validated = $request->validated();
        $statut = in_array((string) $pao->statut, [Pao::STATUS_CLOTURE, Pao::STATUS_ARCHIVE], true)
            ? (string) $pao->statut
            : Pao::STATUS_VALIDE;

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);
        $this->denyUnlessManagePao($user, (int) $validated['direction_id']);
        $objectif = $this->resolveAccessibleObjectif($user, (int) $validated['pas_objectif_id']);

        $operationalObjectives = $this->validatedOperationalObjectives($validated);

        $before = $pao->toArray();
        DB::transaction(function () use ($pao, $before, $validated, $objectif, $statut, $user, $request, $operationalObjectives): void {
            $firstPayload = $this->paoPayload($objectif, $validated, $operationalObjectives[0], $statut, $user);
            $pao->fill($firstPayload);
            $pao->forceFill(['statut' => $statut])->save();
            $this->recordAudit($request, 'pao', 'update', $pao, $before, $pao->toArray());

            $keptObjectiveIds = [];
            foreach ($operationalObjectives as $operationalObjective) {
                $objectiveId = (int) ($operationalObjective['id'] ?? 0);
                $payload = $this->operationalObjectivePayload($pao, $objectif, $validated, $operationalObjective, $statut);

                if ($objectiveId > 0) {
                    $objective = $pao->objectifsOperationnels()->whereKey($objectiveId)->first();
                    if ($objective instanceof ObjectifOperationnel) {
                        $beforeObjective = $objective->toArray();
                        $objective->update($payload);
                        $this->recordAudit($request, 'objectif_operationnel', 'update', $objective, $beforeObjective, $objective->toArray());
                        $keptObjectiveIds[] = (int) $objective->id;
                        continue;
                    }
                }

                $objective = $pao->objectifsOperationnels()->create($payload);
                $this->recordAudit($request, 'objectif_operationnel', 'create', $objective, null, $objective->toArray());
                $keptObjectiveIds[] = (int) $objective->id;
            }

            $pao->objectifsOperationnels()
                ->whereNotIn('id', $keptObjectiveIds)
                ->whereDoesntHave('ptas')
                ->whereDoesntHave('actions')
                ->delete();
        });

        $notificationService->notifyPaoUpdatedForServices($pao->fresh() ?? $pao, $user);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->entityUpdatedMessage(UiLabel::object('pao')).' Objectifs operationnels synchronises.');
    }

    /** Supprime un PAO si aucun impact metier ne bloque l'operation. */
    public function destroy(Request $request, Pao $pao): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($pao->statut === Pao::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de supprimer directement un PAO archivé.']);
        }

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $deletionRequests = app(\App\Services\DeletionRequestService::class);
        // Super Admin et DG peuvent supprimer directement avec cascade (PTAs, OOs,
        // Actions). Les autres roles passent par le workflow de demande validee.
        $canDeleteDirectly = $user->isSuperAdmin() || $user->hasRole(User::ROLE_DG);
        if (! $canDeleteDirectly) {
            $deletionRequest = $deletionRequests->requestBusinessDeletion($pao, $user, (string) $validated['motif'], 'pao');
            $this->recordAudit($request, 'pao', 'deletion_request_create', $deletionRequest, null, $deletionRequest->toArray());

            return redirect()
                ->route('workspace.pao.index')
                ->with('success', 'Demande de suppression du PAO transmise au Super Admin.');
        }

        $before = $pao->toArray();
        $impact = $deletionRequests->impactForEntity($pao);
        $deletionRequests->deleteBusinessTarget($pao);

        $this->recordAudit($request, 'pao', 'delete', $pao, [
            ...$before,
            'deletion_reason' => (string) $validated['motif'],
            'impact' => $impact,
        ], null);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', $this->entityDeletedMessage(UiLabel::object('pao')));
    }

    public function close(Request $request, Pao $pao, PlanningClosureReportService $closureReportService): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);

        if ((string) $pao->statut === Pao::STATUS_ARCHIVE) {
            return back()->withErrors(['general' => 'Impossible de cloturer un PAO archivé.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
            'force_close' => ['nullable', 'boolean'],
        ]);

        $report = $closureReportService->forPao($pao);
        $forceClose = $request->boolean('force_close');

        if ($closureReportService->hasAnomalies($report) && ! $forceClose) {
            return back()
                ->withInput()
                ->with('closure_report', $report)
                ->withErrors(['general' => $this->closureReportErrorMessage($report)]);
        }

        $before = $pao->toArray();
        $pao->forceFill([
            'statut' => Pao::STATUS_CLOTURE,
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pao', 'close', $pao, $before, [
            ...$pao->toArray(),
            'motif' => (string) $validated['motif'],
            'closure_report' => $report,
            'forced_with_anomalies' => $forceClose,
        ]);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', 'PAO clôturé avec rapport d\'anomalies tracé.');
    }

    public function archive(Request $request, Pao $pao): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $this->denyUnlessManagePao($user, (int) $pao->direction_id);

        if ((string) $pao->statut !== Pao::STATUS_CLOTURE) {
            return back()->withErrors(['general' => 'Le PAO doit etre cloture avant archivage.']);
        }

        $validated = $request->validate([
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $before = $pao->toArray();
        $pao->forceFill([
            'statut' => Pao::STATUS_ARCHIVE,
            'valide_le' => now(),
            'valide_par' => $user->id,
        ])->save();

        $this->recordAudit($request, 'pao', 'archive', $pao, $before, [
            ...$pao->toArray(),
            'motif' => (string) $validated['motif'],
        ]);

        return redirect()
            ->route('workspace.pao.index')
            ->with('success', 'PAO archivé.');
    }

    private function canWrite(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_DIRECTION);
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
     * @return array<int, array<string, mixed>>
     */
    private function validationTimeline(Pao $pao): array
    {
        return JournalAudit::query()
            ->with('user:id,name,email,role')
            ->where('module', 'pao')
            ->where('entite_type', $pao::class)
            ->where('entite_id', (int) $pao->id)
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
        $workflow = $this->planningWorkflowSummary();

        if ($user->hasGlobalWriteAccess()) {
            return $workflow['status_options_global'];
        }

        return $workflow['status_options_writer'];
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
        app(ExerciceContext::class)->applyToPas($query);

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

        $selectedYear = app(ExerciceContext::class)->selectedYear();
        if ($selectedYear !== null) {
            $query->whereHas('pasAxe.pas', fn ($pasQuery) => $pasQuery
                ->where('periode_debut', '<=', $selectedYear)
                ->where('periode_fin', '>=', $selectedYear));
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
                    'axe_id' => (int) ($objectif->pas_axe_id ?? 0),
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
        return PasObjectif::query()
            ->with('pasAxe.pas:id,titre,periode_debut,periode_fin')
            ->findOrFail($objectifId);
    }

    /**
     * @return array<string, mixed>
     */
    private function planningWorkflowSummary(): array
    {
        return app(WorkflowSettings::class)->planningWorkflowSummary('pao');
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int, array{id: int|null, libelle: string, service_id: int, echeance: string|null, description: string|null, indicateurs: string|null}>
     */
    private function validatedOperationalObjectives(array $validated): array
    {
        return collect($validated['objectifs_operationnels'] ?? [])
            ->filter(fn ($objective): bool => is_array($objective))
            ->map(fn (array $objective): array => [
                'id' => isset($objective['id']) && is_numeric($objective['id']) ? (int) $objective['id'] : null,
                'libelle' => trim((string) ($objective['libelle'] ?? '')),
                'service_id' => (int) ($objective['service_id'] ?? 0),
                'echeance' => isset($objective['echeance']) && $objective['echeance'] !== ''
                    ? (string) $objective['echeance']
                    : null,
                'description' => null,
                'indicateurs' => null,
            ])
            ->filter(fn (array $objective): bool => $objective['libelle'] !== '' && $objective['service_id'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $validated
     * @param array{id: int|null, libelle: string, service_id: int, echeance: string|null, description: string|null, indicateurs: string|null} $operationalObjective
     * @return array<string, mixed>
     */
    private function paoPayload(PasObjectif $objectif, array $validated, array $operationalObjective, string $statut, User $user): array
    {
        // Note: `statut` n est PAS renvoye ici : il est positionne via forceFill par les
        // callers (store/update) car il n est plus mass-assignable sur Pao.
        $payload = [
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => null,
            'annee' => (int) $validated['annee'],
            'titre' => $this->generatedPaoTitle(
                (int) $validated['direction_id'],
                (int) $validated['annee']
            ),
            'echeance' => $operationalObjective['echeance'],
            'objectif_operationnel' => $operationalObjective['libelle'],
            'resultats_attendus' => $operationalObjective['description'],
            'indicateurs_associes' => $operationalObjective['indicateurs'],
            'exercice_id' => app(ExerciceContext::class)->idForYear((int) $validated['annee']),
        ];

        return $payload;
    }

    /**
     * @param array<string, mixed> $validated
     * @param array{id: int|null, libelle: string, service_id: int, echeance: string|null, description: string|null, indicateurs: string|null} $operationalObjective
     * @return array<string, mixed>
     */
    private function operationalObjectivePayload(Pao $pao, PasObjectif $objectif, array $validated, array $operationalObjective, string $statut): array
    {
        return [
            'pas_id' => (int) $objectif->pasAxe->pas_id,
            'pas_axe_id' => (int) $objectif->pas_axe_id,
            'pas_objectif_id' => (int) $objectif->id,
            'direction_id' => (int) $validated['direction_id'],
            'service_id' => (int) $operationalObjective['service_id'],
            'libelle' => (string) $operationalObjective['libelle'],
            'description' => $operationalObjective['description'],
            'echeance' => $operationalObjective['echeance'],
            'indicateurs' => $operationalObjective['indicateurs'],
            'statut' => $statut,
        ];
    }
    // NOTE: operationalObjectivePayload conserve `statut` car ObjectifOperationnel
    // a son propre $fillable. Si necessaire, le caller pourra appliquer forceFill.

    private function generatedPaoTitle(int $directionId, int $year): string
    {
        $direction = Direction::query()->find($directionId, ['id', 'code', 'libelle']);
        $directionLabel = trim((string) ($direction?->code ?: $direction?->libelle ?: 'DIR-'.$directionId));

        return 'PAO '.$directionLabel.' '.$year;
    }
}
